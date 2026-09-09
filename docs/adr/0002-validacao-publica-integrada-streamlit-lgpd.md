# 2. Validação Pública Integrada no Streamlit com Mascaramento LGPD

Para viabilizar a verificação instantânea por terceiros via QR Code sem depender de infraestrutura externa complexa no início, decidimos integrar a rota pública de validação diretamente na aplicação Streamlit (/?validar={hash}), com suporte a URL base parametrizável e mascaramento obrigatório de CPF (***.123.456-**). Essa abordagem permite testar e operar tanto localmente quanto implantar em nuvem (Streamlit Community Cloud / VPS) apenas ajustando a URL base no painel.
