# Guia de Implantação e Hospedagem no Streamlit Community Cloud

Este documento registra o procedimento operacional padrão para hospedar a aplicação **Certificados Futuro Fácil** no **Streamlit Community Cloud**, garantindo que o validador público de certificados fique permanentemente acessível via internet (QR Code) e o painel administrativo de emissão protegido por credenciais.

---

## 1. Visão Geral da Arquitetura em Nuvem

Conforme definido na decisão arquitetural **ADR-0003**, a aplicação adota um modelo híbrido:
- **Rota Pública de Validação (`/` ou `/?validar=<codigo_sha256>`)**: Permite que alunos, empresas e instituições de ensino validem a autenticidade de certificados instantaneamente via escaneamento do QR Code em smartphones ou navegadores, com mascaramento LGPD de dados sensíveis.
- **Área Administrativa do Emissor (`🔐 Área do Emissor (Admin)`)**: Acessível apenas mediante autenticação com senha mestre, configurada de forma segura nas variáveis de ambiente/segredos da nuvem.
- **Hospedagem Gratuita e Contínua**: O Streamlit Community Cloud conecta-se diretamente ao repositório no GitHub, realizando *deploy* contínuo a cada `git push` no branch `main`.

---

## 2. Pré-Requisitos

1. **Conta no GitHub**: Usuário com acesso de escrita (ex.: `Tel-BR`).
2. **Repositório Git**: Repositório remoto no GitHub (público ou privado).
3. **Conta no Streamlit Community Cloud**: Acesso gratuito via [share.streamlit.io](https://share.streamlit.io), autenticado com a mesma conta do GitHub.

---

## 3. Passo a Passo de Configuração Inicial

### Passo 3.1: Criação do Repositório no GitHub
1. Acesse [github.com/new](https://github.com/new?name=CertificadosFuturoFacil).
2. Defina o nome do repositório como: **`CertificadosFuturoFacil`**.
3. Escolha a visibilidade desejada (**Public** ou **Private**).
4. **IMPORTANTE**: Deixe desmarcadas as opções de inicialização (*Add a README file*, *Add .gitignore*, *Choose a license*), pois o projeto local já possui todo o histórico e arquivos devidamente versionados.
5. Clique em **"Create repository"**.

### Passo 3.2: Vinculação e Envio do Código Local
No terminal do projeto (`C:\Users\Admin\Documents\CertificadosFuturoFacil`), configure o remote e envie os commits:

```powershell
# Adiciona o remote do GitHub caso ainda não exista
git remote add origin https://github.com/Tel-BR/CertificadosFuturoFacil.git

# Envia todo o histórico e branch principal
git push -u origin main
```

> **Nota:** Caso o Windows solicite autenticação, o *Git Credential Manager* abrirá uma janela do navegador para autorizar o acesso da conta GitHub em um clique.

---

## 4. Deploy no Streamlit Community Cloud

1. Acesse [share.streamlit.io](https://share.streamlit.io) e clique em **"Create app"** (ou **"New app"**).
2. No formulário de implantação, preencha:
   - **Repository**: `Tel-BR/CertificadosFuturoFacil`
   - **Branch**: `main`
   - **Main file path**: `app.py`
   - **App URL**: `certificados-futurofacil` (o domínio final será: `https://certificados-futurofacil.streamlit.app`)
3. Clique em **"Advanced settings..."**:
   - **Python version**: Selecione `3.11`.
   - **Secrets**: Insira a senha administrativa mestre no formato TOML:
     ```toml
     ADMIN_PASSWORD = "SuaSenhaSeguraAqui123!"
     ```
4. Clique em **"Deploy!"**.
5. Aguarde o processo de build do container (instalação dos pacotes de `requirements.txt`). Em cerca de 1 a 2 minutos, a aplicação estará ativa online.

---

## 5. Persistência de Dados e Configurações

### Ativos Visuais e Fontes Tipográficas
- O logotipo institucional (`assets/logo.svg` ou PNG), assinatura digitalizada (`assets/assinatura.png`) e as fontes TrueType (`assets/fonts/`) estão versionados no repositório Git, garantindo renderização idêntica em qualquer nó de execução na nuvem.

### Livro de Registro Digital e Banco de Dados
- **Ambiente Local**: Utiliza SQLite (`registros.db`) e exportações em Excel (`livro_registro_certificados.xlsx`).
- **Nuvem (Streamlit Cloud)**: O container do Streamlit Cloud possui sistema de arquivos efêmero (reinicializações periódicas ou hibernação por inatividade podem recriar o container).
- **Boas Práticas de Retenção**:
  1. Sempre que emitir um novo lote na nuvem, faça o download do Livro de Registro atualizado em Excel na aba **📖 Livro de Registro Digital**.
  2. Para retenção externa perpétua sem depender do container, a camada de banco de dados (`core/registry.py`) foi desenhada para permitir conexão futura com PostgreSQL gratuito (Supabase, Neon ou ElephantSQL) através de variável `DATABASE_URL` configurada em Secrets (vide ADR-0003).

---

## 6. Manutenção Contínua e Atualizações

Para aplicar melhorias, correções ou novos recursos na versão em produção:
1. Faça as alterações no código localmente.
2. Execute a suíte de testes para garantir integridade:
   ```powershell
   pytest
   ```
3. Realize o commit e envie para o GitHub:
   ```powershell
   git add .
   git commit -m "feat/fix: descrição da melhoria"
   git push origin main
   ```
4. O Streamlit Community Cloud detecta o novo commit instantaneamente e aplica a atualização em segundo plano sem perda de disponibilidade.
