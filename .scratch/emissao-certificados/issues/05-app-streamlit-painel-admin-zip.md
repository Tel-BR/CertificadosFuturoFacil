# 05: Aplicação Web Streamlit, painel de administrador protegido, pacote ZIP e CLI

**What to build:**
A interface gráfica completa em Streamlit (\pp.py\) com separação de visões: visitantes não autenticados veem apenas a tela limpa de validação pública de certificados; o emissor digita sua senha de administrador para acessar o painel de emissão massiva com download da planilha modelo, upload de arquivo com auditoria visual prévia (tabela com badges coloridos verde/vermelho), formulário de metadados da turma, seletores de cor da paleta, upload e persistência de logo e assinatura em \ssets/\, prévia ao vivo do certificado antes da emissão, barra de progresso e download do pacote final em \.zip\ (PDFs individuais, PDF consolidado e planilha Excel). Inclui também utilitário de terminal \cli.py\ para emissão headless.

**Blocked by:** 03: Motor de renderização em PDF vetorial com Guilloché, minutas legais e consolidador duplex, 04: Módulo de validação pública com mascaramento LGPD de CPF e QR Code funcional

**Status:** resolved

- [x] Rota pública padrão no Streamlit para validação imediata por código ou link de QR Code (/?validar=<codigo>).
- [x] Área administrativa protegida por senha mestre configurável via segredos ou variável de ambiente (ADMIN_PASSWORD).
- [x] Botão funcional para download da planilha modelo modelo_alunos.xlsx.
- [x] Upload de planilha com auditoria prévia em tabela interativa com badges de status de validação de cada aluno e correção inline via st.data_editor.
- [x] Formulário de configuração de turma e ementa, seletores de cores da identidade visual, upload de logo SVG/PNG e assinatura com persistência em assets/.
- [x] Pré-visualização gráfica em tempo real do certificado (anverso e reverso) renderizada em alta resolução via PyMuPDF.
- [x] Processamento do lote com barra de progresso e geração do botão de download do pacote .zip completo.
- [x] Utilitário CLI (cli.py) executável por linha de comando para automação headless com sincronização do Livro de Registro.
- [x] Teste de fumaça e integração ponta a ponta da aplicação via pytest e AppTest oficial do Streamlit.

## Answer

Implementação completa da interface gráfica web Streamlit (`app.py`), do serviço orquestrador de lote e empacotamento ZIP (`core/batch_service.py`), do utilitário de terminal CLI (`cli.py`), do módulo de configuração persistente da instituição (`core/config.py`), e das melhorias solicitadas de retificação pós-emissão e numeração inicial configurável no Livro de Registro Digital (`core/registry.py`).

### Destaques da Implementação:
1. **Roteamento e Validação Pública (LGPD)**: Rota pública raiz e por parâmetro `?validar=<codigo>` que pesquisa assentos no SQLite/PostgreSQL, exibindo dados mascarados sem expor o PDF original.
2. **Painel do Emissor com Autenticação Mestre**: Autenticação com senha mestre parametrizável (`ADMIN_PASSWORD`).
3. **Auditoria e Edição Inline Zero-Burocracia**: Download de `modelo_alunos.xlsx`, upload com análise de CPF e nomes completos, com editor interativo `st.data_editor` que permite corrigir erros de digitação diretamente na interface sem precisar reenviar arquivos.
4. **Customização Visual e Prévias em Tempo Real**: Seletores de paleta (Guilloché e acentos), upload com persistência em `assets/`, e renderização direta do anverso e reverso com PyMuPDF.
5. **Emissão em Lote e Pacote ZIP Semântico**: Processamento com barra de progresso em tempo real e download de ZIP contendo PDFs individuais, PDF consolidado duplex e planilha Excel de auditoria sincronizada.
6. **Retificação Pós-Emissão e Reset de Numeração**: Aba dedicada no Livro de Registro para retificar nomes ou CPFs com sincronização do banco/Excel e reemissão instantânea, além de suporte para numeração inicial arbitrária.
7. **Automação CLI Headless**: `cli.py` aceita parâmetros de linha de comando para automação em lote com retorno formatado no terminal.
8. **Testes**: 74 testes automatizados cobrindo integração do Streamlit via `AppTest`, CLI, validações, serviços de lote e persistência.

