"""Aplicação Web Streamlit - Emissor de Certificados Futuro Fácil.

Fornece:
1. Tela de Validação Pública de Autenticidade (rota raiz e ?validar=<codigo>).
   - Mascaramento estrito de CPF (LGPD: ***.123.456-**)
   - Sem download do PDF original para proteção contra contrafação
2. Área do Emissor (Painel Administrativo) protegida por senha mestre:
   - Download da planilha modelo (.xlsx)
   - Upload com auditoria visual prévia e edição interativa de erros (st.data_editor)
   - Formulário de turma, ementa e carga horária por extenso
   - Personalização de cores e persistência de logotipo e assinatura em assets/
   - Pré-visualização gráfica ao vivo (anverso e reverso em alta definição)
   - Disparo de emissão com barra de progresso e download do pacote .ZIP
   - Livro de Registro Digital com pesquisa, retificação de dados e reset de testes
   - Configurações da Instituição com dados da empresa (CNPJ, Razão Social, etc.)
"""

from datetime import date, datetime
import io
import os
from pathlib import Path
import re
import sys
from typing import Any, Dict, List, Optional

import pandas as pd
from PIL import Image
import pymupdf
import streamlit as st

from core.batch_service import emitir_lote_certificados
from core.config import (
    InstituicaoConfig,
    load_instituicao_config,
    save_instituicao_config,
    validate_instituicao_config,
)
from core.registry import (
    CertificadoRegistro,
    CursoMetadata,
    LivroRegistroManager,
    formatar_carga_horaria_extenso,
)
from core.renderer import (
    CertificateRenderConfig,
    generate_certificate_pdf,
)
from core.spreadsheet import (
    SpreadsheetValidationResult,
    generate_template_spreadsheet,
    read_and_validate_spreadsheet,
)
from core.validator import (
    clean_cnpj,
    clean_cpf,
    format_cnpj,
    format_cpf,
    mask_cpf,
    normalize_name,
    validar_aluno,
    validate_cnpj,
    validate_cpf,
)
from core.validator_service import (
    DEFAULT_VALIDATION_BASE_URL,
    ResultadoValidacaoPublica,
    clean_auth_code,
    validar_certificado,
)


# ==============================================================================
# Configuração da Página e Estilos
# ==============================================================================

st.set_page_config(
    page_title="Certificados Futuro Fácil",
    page_icon="🎓",
    layout="wide",
    initial_sidebar_state="expanded",
)

# Estilização CSS customizada oficial
st.markdown(
    """
    <style>
    .main-header {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        color: #0E7490;
        font-weight: 800;
        margin-bottom: 0px;
    }
    .sub-header {
        color: #64748B;
        font-size: 14px;
        font-weight: 600;
        letter-spacing: 1px;
        margin-top: -5px;
        margin-bottom: 20px;
    }
    .metric-card {
        background-color: #F8FAFC;
        border: 1px solid #E2E8F0;
        border-radius: 8px;
        padding: 15px;
        text-align: center;
    }
    .badge-valid {
        background-color: #DCFCE7;
        color: #166534;
        padding: 3px 8px;
        border-radius: 4px;
        font-weight: bold;
        font-size: 12px;
    }
    .badge-invalid {
        background-color: #FEE2E2;
        color: #991B1B;
        padding: 3px 8px;
        border-radius: 4px;
        font-weight: bold;
        font-size: 12px;
    }
    .validation-card {
        background-color: #F0FDF4;
        border: 2px solid #86EFAC;
        border-radius: 10px;
        padding: 24px;
        margin-top: 15px;
        margin-bottom: 20px;
    }
    .validation-card-error {
        background-color: #FEF2F2;
        border: 2px solid #FCA5A5;
        border-radius: 10px;
        padding: 24px;
        margin-top: 15px;
        margin-bottom: 20px;
    }
    </style>
    """,
    unsafe_allow_html=True,
)


# ==============================================================================
# Helpers e Estado da Sessão
# ==============================================================================

def get_admin_password() -> str:
    """Obtém a senha mestre de administrador do ambiente ou secrets com fallback seguro."""
    try:
        if "ADMIN_PASSWORD" in st.secrets:
            return str(st.secrets["ADMIN_PASSWORD"])
    except Exception:
        pass
    return os.getenv("ADMIN_PASSWORD", "futurofacil123")


def get_manager() -> LivroRegistroManager:
    """Inicializa ou obtém o gerenciador do Livro de Registro Digital."""
    cfg = load_instituicao_config()
    db_url = os.getenv("DATABASE_URL", "registros.db")
    excel_path = "livro_registro_certificados.xlsx"
    return LivroRegistroManager(
        db_url_or_path=db_url,
        excel_export_path=excel_path,
        initial_livro=cfg.initial_livro,
        initial_folha=cfg.initial_folha,
        initial_registro=cfg.initial_registro,
    )


def render_pdf_to_images(pdf_bytes: bytes) -> List[bytes]:
    """Converte páginas de PDF em imagens PNG de alta resolução usando PyMuPDF."""
    images = []
    try:
        doc = pymupdf.open(stream=pdf_bytes, filetype="pdf")
        for page in doc:
            pix = page.get_pixmap(dpi=130)
            images.append(pix.tobytes("png"))
    except Exception as e:
        st.error(f"Erro ao gerar imagem de pré-visualização: {e}")
    return images


# ==============================================================================
# Roteamento de Visões
# ==============================================================================

# Verifica query parameter de validação pública
query_validar = st.query_params.get("validar") or st.query_params.get("codigo")

# Menu de navegação lateral
if Path("assets/logo.svg").exists():
    st.sidebar.image("assets/logo.svg", use_container_width=True)
else:
    st.sidebar.markdown("### 🎓 Futuro Fácil")
st.sidebar.caption("Sistema de Certificados Digitais")
st.sidebar.markdown("---")

modo_selecionado = st.sidebar.radio(
    "Navegação:",
    options=["🔍 Validação Pública", "🔐 Área do Emissor (Admin)"],
    index=0 if query_validar else 1 if st.session_state.get("admin_authenticated", False) else 0,
)


# ==============================================================================
# Visão 1: Validação Pública de Autenticidade (QR Code / Rota Pública)
# ==============================================================================

if modo_selecionado == "🔍 Validação Pública":
    inst_cfg = load_instituicao_config()

    if Path("assets/logo.svg").exists():
        st.image("assets/logo.svg", width=320)
    else:
        st.markdown(f"<h1 class='main-header'>{inst_cfg.nome_fantasia.upper()}</h1>", unsafe_allow_html=True)
    st.markdown(f"<div class='sub-header'>{inst_cfg.tagline} · CONSULTA PÚBLICA DE AUTENTICIDADE</div>", unsafe_allow_html=True)

    st.info(
        "Esta página permite a qualquer pessoa, universidade ou empresa verificar a autenticidade oficial "
        "de certificados de cursos livres emitidos pela instituição, em conformidade com o Decreto Federal nº 5.154/2004, "
        "art. 219 do Código Civil e MP nº 2.200-2/2001."
    )

    codigo_inicial = query_validar if query_validar else ""

    col_input, col_btn = st.columns([4, 1])
    with col_input:
        codigo_pesquisado = st.text_input(
            "Digite o Código de Autenticidade (SHA-256):",
            value=codigo_inicial,
            placeholder="Cole o código de 64 caracteres ou escaneie o QR Code...",
            help="O código alfanumérico de 64 caracteres está impresso no verso do certificado.",
        )
        codigo_limpo = clean_auth_code(codigo_pesquisado)
        if not codigo_limpo:
            st.caption("O código de autenticidade possui 64 caracteres hexadecimais (0-9, A-F) e está impresso no verso do certificado.")
        elif len(codigo_limpo) == 64:
            if all(c in "0123456789ABCDEF" for c in codigo_limpo):
                st.caption("🟢 **Código com 64 caracteres** (formato válido, pronto para validação).")
            else:
                st.caption("⚠️ **64 caracteres**, mas contém caracteres não hexadecimais inválidos.")
        elif len(codigo_limpo) < 64:
            st.caption(f"🟡 **{len(codigo_limpo)}/64 caracteres** (faltam {64 - len(codigo_limpo)} caracteres para completar o código).")
        else:
            st.caption(f"🔴 **{len(codigo_limpo)}/64 caracteres** (excesso de {len(codigo_limpo) - 64} caracteres).")

    with col_btn:
        st.write("")
        st.write("")
        buscar_clicado = st.button("Verificar Documento", type="primary", use_container_width=True)

    codigo_para_validar = codigo_limpo

    if codigo_para_validar or buscar_clicado:
        if not codigo_para_validar:
            st.warning("Por favor, digite ou cole um código de autenticidade válido.")
        else:
            manager = get_manager()
            resultado: ResultadoValidacaoPublica = validar_certificado(codigo_para_validar, manager=manager)

            if resultado.autentico:
                st.markdown(
                    f"""
                    <div class='validation-card'>
                        <h3 style='color: #15803D; margin-top: 0;'>✅ CERTIFICADO AUTÊNTICO E VÁLIDO</h3>
                        <p style='color: #1E293B; margin-bottom: 8px;'>
                            O documento consultado possui registro oficial e veracidade atestada perante o 
                            <strong>Livro de Registro Digital</strong> da instituição emissora.
                        </p>
                    </div>
                    """,
                    unsafe_allow_html=True,
                )

                col1, col2 = st.columns(2)
                with col1:
                    st.markdown("#### Dados do(a) Aluno(a)")
                    st.write(f"**Nome:** {resultado.aluno_nome}")
                    if resultado.aluno_cpf_mascarado and resultado.aluno_cpf_mascarado not in ("-", "Não informado", ""):
                        st.write(f"**CPF (LGPD):** `{resultado.aluno_cpf_mascarado}`")
                        st.caption("O CPF é exibido com mascaramento estrito em conformidade com a LGPD.")

                    st.markdown("#### Entidade Emissora")
                    st.write(f"**Razão Social:** {inst_cfg.razao_social}")
                    st.write(f"**CNPJ:** `{inst_cfg.cnpj}`")
                    st.write(f"**Cidade/UF:** {resultado.cidade}")

                with col2:
                    st.markdown("#### Dados do Curso e Assento")
                    st.write(f"**Curso:** {resultado.curso_nome}")
                    st.write(f"**Carga Horária:** {resultado.carga_horaria} horas ({resultado.carga_horaria_extenso})")
                    st.write(f"**Frequência Apurada:** {resultado.frequencia}%")
                    st.write(f"**Data de Conclusão:** {resultado.data_conclusao}")
                    st.write(f"**Data de Expedição:** {resultado.data_emissao}")
                    st.write(f"**Assento Notarial:** `LIVRO {resultado.livro_numero:02d} · FLS {resultado.folha_numero:03d} · REG {resultado.registro_numero:03d}`")

                st.markdown("---")
                st.markdown(f"**Código de Autenticidade (SHA-256):** `{resultado.codigo_autenticidade}`")
                st.caption(
                    "Fundamentação legal: Arts. 170 e 205 da CF/88, Art. 42 da Lei Federal nº 9.394/96 (LDB), "
                    "Decreto Federal nº 5.154/2004, Art. 219 do Código Civil e Art. 10, § 2º da MP nº 2.200-2/2001."
                )

            else:
                st.markdown(
                    f"""
                    <div class='validation-card-error'>
                        <h3 style='color: #B91C1C; margin-top: 0;'>❌ CERTIFICADO NÃO ENCONTRADO OU INVÁLIDO</h3>
                        <p style='color: #1E293B;'>
                            {resultado.mensagem}
                        </p>
                        <p style='color: #64748B; font-size: 13px; margin-bottom: 0;'>
                            Verifique se todos os 64 caracteres do código foram copiados corretamente.
                        </p>
                    </div>
                    """,
                    unsafe_allow_html=True,
                )


# ==============================================================================
# Visão 2: Área do Emissor (Painel Administrativo Protegido)
# ==============================================================================

elif modo_selecionado == "🔐 Área do Emissor (Admin)":
    admin_password = get_admin_password()

    # Controle de Autenticação
    if not st.session_state.get("admin_authenticated", False):
        if Path("assets/logo.svg").exists():
            st.image("assets/logo.svg", width=300)
        st.markdown("<h2 class='main-header'>Acesso Administrativo</h2>", unsafe_allow_html=True)
        st.write("Digite sua senha de administrador para acessar o painel de emissão e controle.")

        with st.form("form_login"):
            senha_digitada = st.text_input("Senha de Administrador:", type="password")
            btn_entrar = st.form_submit_button("Entrar no Painel", type="primary")

            if btn_entrar:
                if senha_digitada == admin_password:
                    st.session_state["admin_authenticated"] = True
                    st.rerun()
                else:
                    st.error("Senha incorreta. Verifique e tente novamente.")
        st.stop()

    # Botão de Logout na Barra Lateral
    if st.sidebar.button("Sair do Painel Admin", use_container_width=True):
        st.session_state["admin_authenticated"] = False
        st.rerun()

    # Painel Principal Autenticado
    inst_cfg = load_instituicao_config()
    manager = get_manager()

    if Path("assets/logo.svg").exists():
        st.image("assets/logo.svg", width=280)
    st.markdown("<h1 class='main-header'>Painel de Emissão e Auditoria</h1>", unsafe_allow_html=True)
    st.markdown(f"<div class='sub-header'>{inst_cfg.nome_fantasia.upper()} · {inst_cfg.razao_social} (CNPJ: {inst_cfg.cnpj})</div>", unsafe_allow_html=True)

    tab_emissao, tab_livro, tab_config = st.tabs([
        "🎓 Emissão de Certificados",
        "📖 Livro de Registro Digital",
        "⚙️ Configurações da Instituição",
    ])

    # --------------------------------------------------------------------------
    # ABA 1: Emissão de Certificados em Lote
    # --------------------------------------------------------------------------
    with tab_emissao:
        st.subheader("1. Configuração da Turma & Lista de Alunos")

        # PARTE A: Dados Cadastrais da Turma e Ementa
        st.markdown("##### Dados do Curso e Período Letivo")
        col_c1, col_c2 = st.columns([2, 1])
        with col_c1:
            curso_nome = st.text_input(
                "Nome Oficial do Curso Livre:*",
                placeholder="Ex: Formação Prática em Inteligência Artificial e Automação",
            )
        with col_c2:
            carga_horaria = st.number_input(
                "Carga Horária Total (horas):*",
                min_value=1,
                max_value=2000,
                value=40,
                step=1,
            )
            try:
                extenso_prev = formatar_carga_horaria_extenso(int(carga_horaria))
                st.caption(f"Por extenso: *{extenso_prev}*")
            except Exception:
                pass

        col_d1, col_d2, col_d3 = st.columns(3)
        with col_d1:
            data_inicio = st.text_input("Data de Início:", value="01/09/2026", help="Data do 1º encontro (usada no template dinâmico).")
        with col_d2:
            data_conclusao = st.text_input("Data de Conclusão:*", value=datetime.now().strftime("%d/%m/%Y"), help="Data do último encontro.")
        with col_d3:
            data_emissao = st.text_input("Data de Emissão:*", value=datetime.now().strftime("%d/%m/%Y"))

        col_m1, col_m2, col_m3 = st.columns(3)
        with col_m1:
            modalidade = st.text_input("Modalidade:", value="Curso Livre de Capacitação Profissional")
        with col_m2:
            instrutor_nome = st.text_input("Instrutor(a) Responsável:*", value=inst_cfg.instrutor_padrao)
        with col_m3:
            cidade_nome = st.text_input("Cidade de Expedição:*", value=inst_cfg.cidade_padrao)

        ementa_texto = st.text_area(
            "Ementa e Conteúdo Programático (Impresso no verso do certificado):",
            value="Módulo 1: Fundamentos Práticos e Metodologia Aplicada.\n"
                  "Módulo 2: Exercícios, Resolução de Problemas e Estudos de Caso.\n"
                  "Módulo 3: Boas Práticas, Ética Profissional e Projeto de Conclusão.",
            height=100,
        )

        st.markdown("---")

        # PARTE B: Planilha de Alunos, Guia de Regras e Template
        st.markdown("##### Planilha de Alunos e Frequência")

        with st.expander("Guia Resumido: Regras de Preenchimento da Planilha de Alunos", expanded=False):
            st.markdown(
                """
                ### Estrutura de Colunas
                - **`Nome`** *(Obrigatório)*: Nome completo do aluno (mínimo de duas palavras: nome e sobrenome, ex: `Maria de Souza Silva`). Monônimos são rejeitados para segurança jurídica.
                - **`CPF`** *(Obrigatório/Opcional)*: CPF com 11 dígitos, com ou sem pontuação (`529.982.247-25` ou `52998224725`). O sistema higieniza e valida matematicamente os dígitos verificadores (Módulo 11).
                - **`Aproveitamento (%)`** *(Opcional / Manual)*: Digite aqui o percentual manual (ex: `85` ou `100%`) caso já queira fixar o aproveitamento diretamente. **Se preenchido, torna-se a fonte da verdade absoluta e nenhum cálculo é realizado.**
                
                ### Controle de Encontros e Presenças (Cálculo Automático)
                - Você pode registrar os encontros individuais da turma em colunas no formato: **`(4h) AAAA/mmm/DD`** *(sem I ou II)*.
                  - *Exemplo:* `(4h) 2026/set/01`, `(4h) 2026/set/10`
                - **Como funciona o cálculo de presença:**
                  - Digite **`TRUE`** para presença e **`FALSE`** para falta (também aceita `1`/`0`, `V`/`F`, `Sim`/`Não`).
                  - **Se `Aproveitamento (%)` estiver em branco**, o aproveitamento é calculado automaticamente a partir dos booleanos das colunas de encontros (`horas_presentes / carga_horaria_total * 100`).
                - Se não houver coluna de aproveitamento nem colunas de encontros, adota-se 100% por padrão.
                
                ### Modelos de Planilha
                - Clique no botão abaixo para baixar o modelo **já pré-configurado** com as datas de início e conclusão digitadas acima!
                """
            )

        col_tmpl1, col_tmpl2 = st.columns([1, 1])
        with col_tmpl1:
            # Gera modelo dinâmico com base nas datas da turma
            tmpl_buffer_dinamico = io.BytesIO()
            generate_template_spreadsheet(
                tmpl_buffer_dinamico,
                data_inicio=data_inicio,
                data_fim=data_conclusao,
                horas_por_encontro=4,
                incluir_exemplo=True,
            )
            tmpl_buffer_dinamico.seek(0)
            st.download_button(
                label="Baixar Modelo Personalizado (.xlsx)",
                data=tmpl_buffer_dinamico.getvalue(),
                file_name="modelo_alunos_turma.xlsx",
                mime="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
                help="Planilha com colunas de data (4h por encontro) baseadas no período digitado acima e linha de exemplo.",
                type="primary",
            )
        with col_tmpl2:
            # Modelo básico estrito
            tmpl_buffer_basico = io.BytesIO()
            generate_template_spreadsheet(tmpl_buffer_basico, incluir_exemplo=False)
            tmpl_buffer_basico.seek(0)
            st.download_button(
                label="Baixar Modelo Básico (Apenas Nome e CPF)",
                data=tmpl_buffer_basico.getvalue(),
                file_name="modelo_alunos_basico.xlsx",
                mime="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
                help="Planilha simples contendo estritamente as colunas Nome e CPF.",
            )

        col_opt1, col_opt2 = st.columns(2)
        with col_opt1:
            cpf_obrigatorio = st.checkbox(
                "Exigir CPF para todos os alunos",
                value=False,
                help="Se desmarcado, aceita alunos com CPF em branco (útil para oficinas livres e alunos isentos).",
            )
        with col_opt2:
            freq_minima_input = st.number_input(
                "Frequência mínima para aprovação (%):",
                min_value=1,
                max_value=100,
                value=75,
                step=5,
                help="Alunos com presença apurada abaixo desta porcentagem receberão alerta de inconsistência.",
            )

        uploaded_file = st.file_uploader(
            "Carregar planilha preenchida (Excel ou CSV):",
            type=["xlsx", "xls", "csv"],
            help="Carregue o arquivo com as colunas 'Nome' e 'CPF' (e opcionalmente encontros/frequência).",
        )

        alunos_para_emissao: List[Any] = []
        is_lote_valido = False

        if uploaded_file is not None:
            # Leitura e Auditoria prévia
            bytes_data = uploaded_file.getvalue()
            res_auditoria: SpreadsheetValidationResult = read_and_validate_spreadsheet(
                bytes_data,
                cpf_obrigatorio=cpf_obrigatorio,
                frequencia_minima=int(freq_minima_input),
            )

            if res_auditoria.encontros_detectados:
                st.info(
                    f"Encontros identificados na planilha: {len(res_auditoria.encontros_detectados)} encontro(s) "
                    f"totalizando **{res_auditoria.carga_horaria_calculada}h** "
                    f"({res_auditoria.data_inicio_calculada} a {res_auditoria.data_fim_calculada})."
                )

            # Métricas
            col_m1, col_m2, col_m3 = st.columns(3)
            col_m1.metric("Total de Alunos", res_auditoria.total_rows)
            col_m2.metric("Alunos Válidos", res_auditoria.valid_count)
            col_m3.metric("Inconsistências", res_auditoria.invalid_count)

            if res_auditoria.global_errors:
                for err in res_auditoria.global_errors:
                    st.error(f"Erro na planilha: {err}")

            if res_auditoria.total_rows > 0:
                if res_auditoria.invalid_count > 0:
                    st.warning(
                        "Foram encontradas linhas com inconsistências (destacadas abaixo). "
                        "Você pode **corrigir o Nome, o CPF ou a Frequência diretamente na tabela abaixo**, sem precisar refazer a planilha."
                    )
                else:
                    st.info("Você pode **conferir ou ajustar Nome, CPF e Frequência diretamente na tabela abaixo** antes de gerar os certificados:")

                # Tabela interativa SEMPRE editável para conferência prévia total
                df_edit = pd.DataFrame([
                    {
                        "Linha": idx,
                        "Nome": a.nome,
                        "CPF": a.cpf if a.cpf else "",
                        "Frequência (%)": a.frequencia,
                        "Status": "✅ Válido" if a.is_valido else "❌ Erro: " + "; ".join(a.erros),
                    }
                    for idx, a in enumerate(res_auditoria.alunos, start=2)
                ])

                edited_df = st.data_editor(
                    df_edit,
                    disabled=["Linha", "Status"],
                    use_container_width=True,
                    key="editor_alunos",
                )

                # Revalida os dados editados em tempo real
                alunos_revalidados = []
                for _, row in edited_df.iterrows():
                    val = validar_aluno(
                        row["Nome"],
                        row["CPF"],
                        cpf_obrigatorio=cpf_obrigatorio,
                        frequencia=row["Frequência (%)"],
                        frequencia_minima=int(freq_minima_input),
                    )
                    alunos_revalidados.append(val)

                qtd_invalidos_apos_edicao = sum(1 for a in alunos_revalidados if not a.is_valido)

                if qtd_invalidos_apos_edicao == 0 and len(alunos_revalidados) > 0:
                    st.success("Todos os alunos foram validados e estão prontos para emissão.")
                    alunos_para_emissao = alunos_revalidados
                    is_lote_valido = True
                else:
                    st.error(f"Ainda restam {qtd_invalidos_apos_edicao} linha(s) com erros. Corrija na tabela acima para liberar a emissão.")
                    alunos_para_emissao = alunos_revalidados
                    is_lote_valido = False

        st.markdown("---")
        st.subheader("2. Identidade Visual e Assinatura")

        col_cor1, col_cor2 = st.columns(2)
        with col_cor1:
            cor_primaria = st.color_picker("Cor Primária (Guilloché e Títulos):", value=inst_cfg.primary_color)
        with col_cor2:
            cor_secundaria = st.color_picker("Cor Secundária (Acentos e Selo):", value=inst_cfg.secondary_color)

        col_logo, col_sig = st.columns(2)
        logo_path_final = inst_cfg.logo_path
        with col_logo:
            upload_logo = st.file_uploader("Logotipo Institucional (SVG ou PNG):", type=["svg", "png"])
            if upload_logo is not None:
                salvar_logo_padrao = st.checkbox("Definir como logotipo padrão institucional", value=False)
                # Salva arquivo temporário ou em assets
                assets_dir = Path("assets")
                assets_dir.mkdir(parents=True, exist_ok=True)
                ext = upload_logo.name.split(".")[-1].lower()
                logo_dest = assets_dir / f"logo_institucional.{ext}" if salvar_logo_padrao else assets_dir / f"temp_logo.{ext}"
                logo_dest.write_bytes(upload_logo.getvalue())
                logo_path_final = str(logo_dest)
                if salvar_logo_padrao:
                    inst_cfg.logo_path = str(logo_dest)
                    save_instituicao_config(inst_cfg)
                    st.success("Logotipo salvo como padrão institucional!")

        assinatura_path_final = inst_cfg.signature_path
        with col_sig:
            tipo_assinatura = st.radio(
                "Tipo de Assinatura:",
                options=["Imagem digitalizada (PNG)", "Linha em branco para assinatura à mão"],
                index=0 if inst_cfg.signature_path and Path(inst_cfg.signature_path).exists() else 1,
            )
            if tipo_assinatura == "Imagem digitalizada (PNG)":
                upload_sig = st.file_uploader("Upload da Assinatura Digitalizada (PNG transparente):", type=["png"])
                if upload_sig is not None:
                    salvar_sig_padrao = st.checkbox("Definir como assinatura padrão institucional", value=False)
                    assets_dir = Path("assets")
                    assets_dir.mkdir(parents=True, exist_ok=True)
                    sig_dest = assets_dir / "assinatura.png" if salvar_sig_padrao else assets_dir / "temp_assinatura.png"
                    sig_dest.write_bytes(upload_sig.getvalue())
                    assinatura_path_final = str(sig_dest)
                    if salvar_sig_padrao:
                        inst_cfg.signature_path = str(sig_dest)
                        save_instituicao_config(inst_cfg)
                        st.success("Assinatura salva como padrão institucional!")
            else:
                assinatura_path_final = None

        url_base_validacao = st.text_input(
            "URL Base da Validação Pública (QR Code):",
            value=inst_cfg.validation_base_url,
            help="Domínio público da consulta de autenticidade gravado no QR Code impresso no verso.",
        )

        st.markdown("---")
        st.subheader("3. Pré-Visualização ao Vivo (Frente e Verso)")

        with st.expander("Pré-visualização gráfica do certificado (Anverso e Verso)", expanded=False):
            # Prepara registro de amostra
            aluno_preview = alunos_para_emissao[0] if alunos_para_emissao else validar_aluno("Aluno Amostra da Silva", "529.982.247-25")
            nome_curso_prev = curso_nome.strip() if curso_nome.strip() else "Curso Prático de Formação Profissional"

            reg_preview = CertificadoRegistro(
                id=1,
                codigo_autenticidade="0" * 64,
                aluno_nome=aluno_preview.nome,
                aluno_cpf=aluno_preview.cpf,
                aluno_cpf_mascarado=aluno_preview.cpf_mascarado if aluno_preview.cpf_mascarado else "-",
                curso_nome=nome_curso_prev,
                carga_horaria=int(carga_horaria),
                carga_horaria_extenso=formatar_carga_horaria_extenso(int(carga_horaria)),
                data_inicio=data_inicio,
                data_conclusao=data_conclusao,
                data_emissao=data_emissao,
                modalidade=modalidade,
                instrutor=instrutor_nome,
                cidade=cidade_nome,
                ementa=ementa_texto,
                livro_numero=inst_cfg.initial_livro,
                folha_numero=inst_cfg.initial_folha,
                registro_numero=inst_cfg.initial_registro,
                frequencia=aluno_preview.frequencia,
            )

            cfg_render_prev = CertificateRenderConfig(
                primary_color=cor_primaria,
                secondary_color=cor_secundaria,
                logo_path=logo_path_final,
                signature_image_path=assinatura_path_final,
                validation_base_url=url_base_validacao,
                institution_name=inst_cfg.nome_fantasia,
                institution_tagline=inst_cfg.tagline,
                instructor_default=instrutor_nome,
                city_default=cidade_nome,
                cnpj=inst_cfg.cnpj,
                razao_social=inst_cfg.razao_social,
            )

            pdf_prev_bytes = generate_certificate_pdf(reg_preview, config=cfg_render_prev)
            imgs_prev = render_pdf_to_images(pdf_prev_bytes)

            if len(imgs_prev) >= 2:
                col_prev_frente, col_prev_verso = st.columns(2)
                with col_prev_frente:
                    st.markdown("**Anverso (Frente):**")
                    st.image(imgs_prev[0], use_container_width=True)
                with col_prev_verso:
                    st.markdown("**Reverso (Verso):**")
                    st.image(imgs_prev[1], use_container_width=True)

        st.markdown("---")
        st.subheader("4. Emissão do Lote e Pacote ZIP")

        # Validação de CNPJ cadastrado
        erros_institucionais = validate_instituicao_config(inst_cfg)
        if erros_institucionais:
            st.error("Não é possível emitir certificados sem a instituição estar devidamente configurada:")
            for e in erros_institucionais:
                st.write(f"- {e}")
            st.info("Acesse a aba '⚙️ Configurações da Instituição' para preencher seu CNPJ e dados cadastrais.")

        pode_emitir = is_lote_valido and bool(curso_nome.strip()) and not erros_institucionais

        if not curso_nome.strip():
            st.warning("Preencha o Nome Oficial do Curso para habilitar o botão de emissão.")

        if st.button("Emitir Lote de Certificados", type="primary", disabled=not pode_emitir, use_container_width=True):
            with st.spinner("Processando lote de certificados..."):
                prog_bar = st.progress(0)
                status_txt = st.empty()

                def callback_progresso(current, total, msg):
                    if total > 0:
                        prog_bar.progress(int((current / total) * 100))
                    status_txt.text(msg)

                curso_meta = CursoMetadata(
                    curso_nome=curso_nome.strip(),
                    carga_horaria=int(carga_horaria),
                    data_conclusao=data_conclusao,
                    data_inicio=data_inicio,
                    data_emissao=data_emissao,
                    modalidade=modalidade,
                    instrutor=instrutor_nome,
                    cidade=cidade_nome,
                    ementa=ementa_texto,
                )

                config_render = CertificateRenderConfig(
                    primary_color=cor_primaria,
                    secondary_color=cor_secundaria,
                    logo_path=logo_path_final,
                    signature_image_path=assinatura_path_final,
                    validation_base_url=url_base_validacao,
                    institution_name=inst_cfg.nome_fantasia,
                    institution_tagline=inst_cfg.tagline,
                    instructor_default=instrutor_nome,
                    city_default=cidade_nome,
                    cnpj=inst_cfg.cnpj,
                    razao_social=inst_cfg.razao_social,
                )

                lote_resultado = emitir_lote_certificados(
                    alunos=alunos_para_emissao,
                    curso=curso_meta,
                    config=config_render,
                    manager=manager,
                    progress_callback=callback_progresso,
                )

                prog_bar.progress(100)
                status_txt.text("Emissão concluída com sucesso!")
                st.balloons()

                st.success(f"Lote de {lote_resultado.total_emitidos} certificados emitido com sucesso.")

                # Nomenclatura semântica: certificados_{slug}_{data}.zip
                slug_curso = re.sub(r"[^\w\-]", "_", curso_nome.strip().lower())
                slug_curso = re.sub(r"_+", "_", slug_curso).strip("_")
                data_slug = datetime.now().strftime("%Y-%m-%d")
                zip_filename = f"certificados_{slug_curso}_{data_slug}.zip"

                st.download_button(
                    label="Baixar Pacote Completo (.ZIP)",
                    data=lote_resultado.zip_bytes,
                    file_name=zip_filename,
                    mime="application/zip",
                    type="primary",
                    help="O pacote ZIP contém todos os PDFs individuais, o PDF duplex para gráfica e o Excel de controle.",
                )

    # --------------------------------------------------------------------------
    # ABA 2: Livro de Registro Digital
    # --------------------------------------------------------------------------
    with tab_livro:
        st.subheader("Assentos no Livro de Registro Digital")

        registros = manager.list_all_certificates()
        proximo_livro, proxima_folha, proximo_reg = manager.get_next_numbers()

        col_s1, col_s2, col_s3, col_s4 = st.columns(4)
        col_s1.metric("Total de Registros Emitidos", len(registros))
        col_s2.metric("Próximo Livro", f"Livro {proximo_livro}")
        col_s3.metric("Próxima Folha", f"Folha {proxima_folha:03d}")
        col_s4.metric("Próximo Registro", f"Registro {proximo_reg:03d}")

        # Download do Excel Mestre
        excel_mestre_buffer = io.BytesIO()
        manager.export_to_excel(excel_mestre_buffer)
        excel_mestre_buffer.seek(0)
        st.download_button(
            label="Baixar Planilha Consolidada do Livro (.xlsx)",
            data=excel_mestre_buffer.getvalue(),
            file_name="livro_registro_certificados.xlsx",
            mime="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
        )

        st.markdown("---")
        busca = st.text_input("Filtrar registros por Aluno, CPF ou Curso:", placeholder="Digite para filtrar...")

        registros_filtrados = registros
        if busca.strip():
            termo_busca = busca.strip().lower()
            registros_filtrados = [
                r for r in registros
                if termo_busca in r.aluno_nome.lower() or termo_busca in r.aluno_cpf or termo_busca in r.aluno_cpf_mascarado or termo_busca in r.curso_nome.lower()
            ]

        if registros_filtrados:
            df_livro = pd.DataFrame([
                {
                    "Livro": f"Livro {r.livro_numero:02d}",
                    "Folha": f"Fls {r.folha_numero:03d}",
                    "Registro": f"Reg {r.registro_numero:03d}",
                    "Aluno": r.aluno_nome,
                    "CPF": r.aluno_cpf_mascarado,
                    "Curso": r.curso_nome,
                    "Carga Horária": f"{r.carga_horaria}h",
                    "Expedição": r.data_emissao,
                    "Código": r.codigo_autenticidade[:16] + "...",
                }
                for r in reversed(registros_filtrados)
            ])
            st.dataframe(df_livro, use_container_width=True)
        else:
            st.info("Nenhum registro encontrado.")

        # Seção de Consulta, 2ª Via e Retificação Pós-Emissão
        st.markdown("---")
        st.subheader("Consulta, 2ª Via e Retificação de Aluno")
        st.write(
            "Consulte qualquer certificado emitido para baixar uma 2ª via individual em PDF ou "
            "corrigir eventuais erros de digitação em Nome e CPF com sincronização automática do Livro de Registro."
        )

        if registros:
            opcoes_alunos = {
                f"Reg {r.registro_numero:03d} - {r.aluno_nome} ({r.curso_nome})": r.codigo_autenticidade
                for r in registros
            }
            aluno_selecionado_label = st.selectbox("Selecione o certificado do aluno:", options=list(opcoes_alunos.keys()))
            codigo_selecionado = opcoes_alunos[aluno_selecionado_label]
            reg_atual = manager.get_certificate_by_code(codigo_selecionado)

            if reg_atual:
                cfg_render_ind = CertificateRenderConfig(
                    primary_color=inst_cfg.primary_color,
                    secondary_color=inst_cfg.secondary_color,
                    logo_path=inst_cfg.logo_path,
                    signature_image_path=inst_cfg.signature_path,
                    validation_base_url=inst_cfg.validation_base_url,
                    institution_name=inst_cfg.nome_fantasia,
                    institution_tagline=inst_cfg.tagline,
                    instructor_default=inst_cfg.instrutor_padrao,
                    city_default=inst_cfg.cidade_padrao,
                    cnpj=inst_cfg.cnpj,
                    razao_social=inst_cfg.razao_social,
                )
                pdf_atual_bytes = generate_certificate_pdf(reg_atual, config=cfg_render_ind)
                slug_aluno = re.sub(r"[^\w\-]", "_", reg_atual.aluno_nome.strip().lower())

                col_dl_ind1, col_dl_ind2 = st.columns([1, 1])
                with col_dl_ind1:
                    st.download_button(
                        label=f"Baixar Certificado em PDF (Reg {reg_atual.registro_numero:03d} - {reg_atual.aluno_nome})",
                        data=pdf_atual_bytes,
                        file_name=f"certificado_{reg_atual.registro_numero:04d}_{slug_aluno}.pdf",
                        mime="application/pdf",
                        type="primary",
                        help="Gera e baixa o PDF oficial em alta resolução deste certificado individual.",
                    )

                with st.expander("Corrigir dados cadastrais (Retificação de Nome ou CPF)", expanded=False):
                    with st.form("form_retificacao"):
                        novo_nome_input = st.text_input("Nome Completo Corrigido:", value=reg_atual.aluno_nome)
                        novo_cpf_input = st.text_input("CPF Corrigido:", value=reg_atual.aluno_cpf)

                        btn_salvar_retificacao = st.form_submit_button("Salvar Retificação e Atualizar Livro", type="primary")

                        if btn_salvar_retificacao:
                            try:
                                reg_atualizado = manager.update_certificate(
                                    codigo_autenticidade=codigo_selecionado,
                                    novo_nome=novo_nome_input,
                                    novo_cpf=novo_cpf_input,
                                )
                                st.success(f"Registro retificado com sucesso para {reg_atualizado.aluno_nome}!")

                                # Gera novo PDF individual retificado
                                pdf_ret_bytes = generate_certificate_pdf(reg_atualizado, config=cfg_render_ind)
                                slug_ret = re.sub(r"[^\w\-]", "_", reg_atualizado.aluno_nome.strip().lower())
                                st.download_button(
                                    label="Baixar PDF Individual Retificado",
                                    data=pdf_ret_bytes,
                                    file_name=f"certificado_{reg_atualizado.registro_numero:04d}_{slug_ret}_retificado.pdf",
                                    mime="application/pdf",
                                    type="primary",
                                )
                            except Exception as ex:
                                st.error(f"Erro ao retificar registro: {ex}")

        # Seção de Manutenção e Reset de Testes
        st.markdown("---")
        with st.expander("Ambiente de Homologação / Testes (Redefinir Base de Dados)", expanded=False):
            st.warning(
                "Utilize esta ferramenta para apagar todos os certificados emitidos durante a fase de testes e "
                "redefinir a numeração inicial do Livro de Registro Digital antes de entrar em produção definitiva."
            )
            col_r1, col_r2, col_r3 = st.columns(3)
            with col_r1:
                reset_livro = st.number_input("Novo Livro Inicial:", min_value=1, value=inst_cfg.initial_livro)
            with col_r2:
                reset_folha = st.number_input("Nova Folha Inicial:", min_value=1, value=inst_cfg.initial_folha)
            with col_r3:
                reset_reg = st.number_input("Novo Registro Inicial:", min_value=1, value=inst_cfg.initial_registro)

            confirmacao_reset = st.checkbox("Confirmo que desejo apagar permanentemente todos os registros de teste atuais.")
            if st.button("Resetar Banco de Dados e Redefinir Numeração", type="primary", disabled=not confirmacao_reset):
                manager.reset_database(
                    initial_livro=int(reset_livro),
                    initial_folha=int(reset_folha),
                    initial_registro=int(reset_reg),
                )
                inst_cfg.initial_livro = int(reset_livro)
                inst_cfg.initial_folha = int(reset_folha)
                inst_cfg.initial_registro = int(reset_reg)
                save_instituicao_config(inst_cfg)
                st.success("Banco de dados resetado com sucesso! A numeração inicial foi redefinida.")
                st.rerun()

    # --------------------------------------------------------------------------
    # ABA 3: Configurações da Instituição
    # --------------------------------------------------------------------------
    with tab_config:
        st.subheader("Dados Cadastrais da Empresa e Parâmetros Padrão")
        st.write(
            "Preencha os dados da sua instituição uma única vez. Eles ficam salvos de forma permanente "
            "e são utilizados automaticamente na emissão dos certificados, no rodapé legal (PDF) "
            "e no cabeçalho da página pública de validação."
        )

        with st.form("form_config_instituicao"):
            col_conf1, col_conf2 = st.columns(2)
            with col_conf1:
                conf_razao = st.text_input("Razão Social da Empresa:*", value=inst_cfg.razao_social)
                conf_fantasia = st.text_input("Nome Fantasia:*", value=inst_cfg.nome_fantasia)
                conf_cnpj = st.text_input("CNPJ:*", value=inst_cfg.cnpj, help="Ex: 11.222.333/0001-81")

            with col_conf2:
                conf_cidade = st.text_input("Cidade Sede:*", value=inst_cfg.cidade_padrao)
                conf_uf = st.text_input("UF:*", value=inst_cfg.uf_padrao, max_chars=2)
                conf_instrutor = st.text_input("Instrutor(a) Padrão:*", value=inst_cfg.instrutor_padrao)

            conf_tagline = st.text_input("Tagline / Lema Institucional:", value=inst_cfg.tagline)

            st.markdown("#### Ponto de Partida Numérico do Livro de Registro")
            registros_existentes = manager.list_all_certificates()
            travado = len(registros_existentes) > 0

            col_p1, col_p2, col_p3 = st.columns(3)
            with col_p1:
                conf_livro = st.number_input("Livro Inicial:", min_value=1, value=inst_cfg.initial_livro, disabled=travado)
            with col_p2:
                conf_folha = st.number_input("Folha Inicial:", min_value=1, value=inst_cfg.initial_folha, disabled=travado)
            with col_p3:
                conf_reg = st.number_input("Registro Inicial:", min_value=1, value=inst_cfg.initial_registro, disabled=travado)

            if travado:
                st.caption("🔒 A numeração inicial está travada porque já existem certificados emitidos. Para alterar, use a aba 'Livro de Registro' -> 'Zona de Testes'.")
            else:
                st.caption("Defina por qual livro, folha e número de registro o primeiro lote emitido começará (ex: Livro 1, Folha 14, Registro 14).")

            btn_salvar_config = st.form_submit_button("💾 Salvar Configurações Institucionais", type="primary")

            if btn_salvar_config:
                nova_config = InstituicaoConfig(
                    razao_social=conf_razao.strip(),
                    nome_fantasia=conf_fantasia.strip(),
                    cnpj=conf_cnpj.strip(),
                    cidade_padrao=conf_cidade.strip(),
                    uf_padrao=conf_uf.strip().upper(),
                    instrutor_padrao=conf_instrutor.strip(),
                    tagline=conf_tagline.strip(),
                    primary_color=inst_cfg.primary_color,
                    secondary_color=inst_cfg.secondary_color,
                    initial_livro=int(conf_livro),
                    initial_folha=int(conf_folha),
                    initial_registro=int(conf_reg),
                    validation_base_url=inst_cfg.validation_base_url,
                    logo_path=inst_cfg.logo_path,
                    signature_path=inst_cfg.signature_path,
                )

                erros_validacao = validate_instituicao_config(nova_config)
                if erros_validacao:
                    for err in erros_validacao:
                        st.error(err)
                else:
                    save_instituicao_config(nova_config)
                    st.success("Configurações institucionais salvas com sucesso!")
                    st.rerun()
