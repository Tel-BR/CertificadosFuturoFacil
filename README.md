# Certificados Futuro Fácil 🎓

Sistema corporativo e notarial para **emissão, registro e validação pública de certificados digitais** em conformidade com as diretrizes do MEC/LDB para cursos livres e princípios de proteção de dados (LGPD).

---

## 🌟 Principais Funcionalidades

- **Emissão Automatizada em Lote**:
  - Geração de PDFs vetoriais frente e verso (*duplex*) em alta definição (300 DPI).
  - Molduras com guilhochês numismáticos matemáticos para prevenção de fraudes.
  - Carimbo digital de segurança com cálculo de hash criptográfico **SHA-256**.
  - QR Code dinâmico impresso no certificado apontando diretamente para a validação em nuvem.
  - Ementa programática completa com discriminação de carga horária e instrutor no verso.
  - Tratamento elegante para emissões com ou sem CPF.

- **Importação Inteligente de Planilhas**:
  - Leitura flexível com cálculo automático de frequência baseada em encontros/datas ou percentual manual direto.
  - Tabela interativa para auditoria e edição prévia dos dados dos alunos antes da emissão.
  - Geração de modelo dinâmico `.xlsx` customizado com os dados da turma.

- **Livro de Registro Digital**:
  - Registro notarial sequencial de Livro, Folha e Registro rastreáveis.
  - Exportação instantânea para planilha oficial em Excel (`livro_registro_certificados.xlsx`).
  - Emissão de 2ª via individual em PDF diretamente pelo painel.

- **Validação Pública em Nuvem (LGPD)**:
  - Rota pública rápida (`/?validar=<codigo>`) para consulta instantânea via QR Code.
  - Mascaramento de dados sensíveis (apenas iniciais do nome e CPF truncado).
  - Higienização automática de espaços e quebras de linha acidentais na cópia do SHA-256.

- **Segurança e Identidade Visual**:
  - Área administrativa protegida por senha mestre configurável via `secrets.toml`.
  - Gestão permanente de logotipo oficial (SVG/PNG) e assinatura digitalizada (PNG transparente).

---

## 🚀 Como Executar Localmente

### 1. Clonar o repositório e preparar o ambiente:
```bash
git clone https://github.com/Tel-BR/CertificadosFuturoFacil.git
cd CertificadosFuturoFacil

# Criar ambiente virtual
python -m venv venv
venv\Scripts\activate  # Windows
# source venv/bin/activate  # Linux/macOS

# Instalar dependências
pip install -r requirements.txt
```

### 2. Iniciar a aplicação:
```bash
streamlit run app.py
```
Acesse no navegador: `http://localhost:8501`.

---

## 🧪 Executando os Testes Automatizados

O projeto possui cobertura completa de testes unitários e de integração:
```bash
pytest
```

---

## ☁️ Implantação e Hospedagem na Nuvem

A aplicação está configurada para deploy simplificado e gratuito no **Streamlit Community Cloud**.

Para instruções passo a passo detalhadas, consulte:
👉 **[Guia de Implantação no Streamlit Cloud](docs/deploy-streamlit-cloud.md)**

---

## 🏛️ Decisões Arquiteturais (ADRs)

- [ADR-0001: Livro de Registro em SQLite com Exportação para Excel](docs/adr/0001-livro-registro-sqlite-com-exportacao-excel.md)
- [ADR-0002: Validação Pública Integrada no Streamlit com Mascaramento LGPD](docs/adr/0002-validacao-publica-integrada-streamlit-lgpd.md)
- [ADR-0003: Validador Público em Nuvem com Proteção por Senha do Emissor e Banco Híbrido](docs/adr/0003-validador-nuvem-hibrido-protecao-admin.md)

---

## 📄 Licença e Propriedade

Desenvolvido para a **Futuro Fácil Capacitação Digital**. Todos os direitos reservados.
