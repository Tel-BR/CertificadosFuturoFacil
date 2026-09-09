# 05: Aplicação Web Streamlit, painel de administrador protegido, pacote ZIP e CLI

**What to build:**
A interface gráfica completa em Streamlit (\pp.py\) com separação de visões: visitantes não autenticados veem apenas a tela limpa de validação pública de certificados; o emissor digita sua senha de administrador para acessar o painel de emissão massiva com download da planilha modelo, upload de arquivo com auditoria visual prévia (tabela com badges coloridos verde/vermelho), formulário de metadados da turma, seletores de cor da paleta, upload e persistência de logo e assinatura em \ssets/\, prévia ao vivo do certificado antes da emissão, barra de progresso e download do pacote final em \.zip\ (PDFs individuais, PDF consolidado e planilha Excel). Inclui também utilitário de terminal \cli.py\ para emissão headless.

**Blocked by:** 03: Motor de renderização em PDF vetorial com Guilloché, minutas legais e consolidador duplex, 04: Módulo de validação pública com mascaramento LGPD de CPF e QR Code funcional

**Status:** ready-for-agent

- [ ] Rota pública padrão no Streamlit para validação imediata por código ou link de QR Code (\/?validar=<codigo>\).
- [ ] Área administrativa protegida por senha mestre configurável via segredos ou variável de ambiente (\ADMIN_PASSWORD\).
- [ ] Botão funcional para download da planilha modelo \modelo_alunos.xlsx\.
- [ ] Upload de planilha com auditoria prévia em tabela interativa com badges de status de validação de cada aluno.
- [ ] Formulário de configuração de turma e ementa, seletores de cores da identidade visual, upload de logo SVG/PNG e assinatura com persistência em \ssets/\.
- [ ] Pré-visualização gráfica em tempo real do certificado (anverso e reverso) antes da emissão do lote.
- [ ] Processamento do lote com barra de progresso e geração do botão de download do pacote \.zip\ completo.
- [ ] Utilitário CLI (\cli.py\) executável por linha de comando para automação.
- [ ] Teste de fumaça e integração ponta a ponta da aplicação.
