# Especificação: Emissor de Certificados Futuro Fácil

**Status:** ready-for-agent

## Problem Statement

Microempreendedores individuais (MEI - CNAE 8599-6/04) e instituições de capacitação profissional enfrentam um processo manual, moroso e juridicamente frágil para expedir certificados de cursos livres. Certificados elaborados artesanalmente em editores genéricos (Word, Canva) carecem de respaldo legal expresso (CF/88, LDB, Decreto Federal nº 5.154/2004), não possuem livro de registro formal (indispensável para que alunos comprovem horas complementares em universidades e provas de títulos em concursos), não contam com travas antifraude de padrão numismático (guilloché e QR Code rastreável) e frequentemente violam a LGPD ao expor dados cadastrais completos em consultas públicas.

## Solution

Uma aplicação em Python com interface Web interativa em Streamlit e motor CLI desacoplado para emissão massiva automatizada de certificados em PDF A4 paisagem (frente e verso), provida de:
1. Borda de segurança numismática (guilloché matemático vetorial) e logotipo institucional;
2. Livro de Registro Digital com numeração contínua e imutável persistida em banco SQLite local e exportada para Excel;
3. Sistema de validação pública instantânea por código ou QR Code com mascaramento de dados pessoais (LGPD);
4. Auditoria visual prévia de planilhas com alertas de inconsistência;
5. Saída em pacote completo com PDFs individuais duplex, PDF unificado intercalado pronto para gráfica duplex e arquivo ZIP.

## User Stories

1. Como emissor de cursos livres, quero baixar uma planilha modelo pré-formatada em Excel contendo apenas as colunas \"Nome\" e \"CPF\", para que eu possa preencher os dados dos alunos sem dúvidas.
2. Como emissor de cursos livres, quero carregar uma planilha Excel (.xlsx) ou CSV com os alunos, para que eu possa emitir certificados em lote com um único clique.
3. Como emissor de cursos livres, quero ver uma tabela de pré-visualização com indicadores visuais coloridos (badges verde/vermelho) antes de emitir, para que eu possa identificar e corrigir CPFs inválidos ou nomes em branco.
4. Como emissor de cursos livres, quero definir os metadados do curso (nome do curso, carga horária, datas de início e término, modalidade, instrutor, ementa e cidade) através de formulário visual, para que eu não precise repetir essas informações em cada linha da planilha.
5. Como emissor de cursos livres, quero que a carga horária seja convertida automaticamente para texto por extenso em português (ex: 40 -> \"quarenta horas\"), para assegurar conformidade formal do texto de concessão.
6. Como emissor de cursos livres, quero fazer upload do logotipo institucional (SVG ou PNG) e mantê-lo salvo no sistema, para que a identidade visual dos certificados seja preservada entre sessões.
7. Como emissor de cursos livres, quero poder escolher entre enviar uma imagem de assinatura digitalizada ou deixar uma linha em branco para assinatura física à caneta, para atender tanto entregas digitais quanto impressas.
8. Como emissor de cursos livres, quero poder selecionar as cores primária e secundária do certificado no painel, para que a borda guilloché e os títulos combinem com a paleta da minha instituição.
9. Como emissor de cursos livres, quero visualizar uma prévia ao vivo (preview) da frente e do verso do certificado no navegador antes de disparar o processamento do lote, para garantir que o layout e alinhamento estejam corretos.
10. Como emissor de cursos livres, quero que o anverso do certificado contenha uma moldura geométrica de segurança tipo guilloché calculada matematicamente em vetor, para conferir credibilidade visual e proteção contra contrafação.
11. Como emissor de cursos livres, quero que o anverso apresente a cláusula jurídica resumida citando o art. 170 da CF/88 e o Decreto Federal nº 5.154/2004, para esclarecer formalmente a natureza do curso livre de capacitação.
12. Como emissor de cursos livres, quero que o verso do certificado apresente a ementa completa e o conteúdo programático, para que os alunos consigam convalidar horas complementares em instituições de ensino superior.
13. Como emissor de cursos livres, quero que o verso contenha a fundamentação jurídica integral (CF/88, LDB, Decreto nº 5.154/2004, MP nº 2.200-2/2001 e art. 219 do Código Civil), para assegurar validade jurídica em provas de títulos de concursos e auditorias.
14. Como emissor de cursos livres, quero que cada certificado receba um Código de Autenticidade imutável (hash criptográfico ou UUID), para permitir rastreabilidade individual inequívoca.
15. Como emissor de cursos livres, quero que cada certificado receba o assento formal do Livro de Registro Digital (Livro nº, Folha nº e Registro nº), para manter o registro histórico cronológico da instituição.
16. Como emissor de cursos livres, quero que o controle sequencial do livro seja salvo em banco de dados SQLite local, para que novas emissões continuem a numeração correta mesmo em lotes futuros.
17. Como emissor de cursos livres, quero que a paginação padrão adote 1 registro por folha e virada automática a cada 100 folhas por livro (com opções avançadas ajustáveis), para manter a organização documental tradicional de livros de registro.
18. Como emissor de cursos livres, quero que uma planilha mestre de controle (livro_registro_certificados.xlsx) seja gerada e atualizada automaticamente a cada lote, para manter uma trilha de auditoria legível em Excel.
19. Como emissor de cursos livres, quero que sejam gerados PDFs individuais de cada aluno (frente e verso), para que eu possa enviá-los diretamente por e-mail ou WhatsApp.
20. Como emissor de cursos livres, quero que seja gerado um arquivo PDF consolidado unificado em duplex intercalado (Frente A, Verso A, Frente B, Verso B...), para que eu possa enviar para gráfica rápida ou imprimir em impressora duplex sem necessidade de ordenar manualmente as páginas.
21. Como emissor de cursos livres, quero baixar um arquivo .zip contendo todos os certificados individuais, o PDF consolidado e a planilha de registro, para agilizar o download em conexões lentas.
22. Como avaliador de concurso, empresa ou universidade, quero apontar a câmera do smartphone para o QR Code no verso do certificado, para consultar imediatamente os dados oficiais de conclusão do curso.
23. Como aluno, quero que meu CPF seja exibido com mascaramento (***.123.456-**) na página pública de validação, para que minha privacidade seja respeitada conforme a LGPD.
24. Como administrador do sistema, quero configurar a URL base da validação pública no painel, para que o QR Code aponte para localhost durante testes ou para o domínio público quando implantado em nuvem.
25. Como desenvolvedor ou usuário avançado, quero poder executar a emissão de certificados via terminal (CLI), para possibilitar automações em lote e scripts programados.

## Implementation Decisions

1. **Camada de Dados e Persistência Híbrida**:
   - Conforme ADR-0001 e ADR-0003, o sistema opera com motor de persistência híbrido: SQLite local (`registros.db`) em ambiente de desenvolvimento/local, e suporte imediato a banco relacional em nuvem gratuito (ex: Supabase / Neon / PostgreSQL) via string de conexão configurável (`DATABASE_URL`), garantindo que na nuvem (Streamlit Cloud) os registros nunca sejam perdidos por reinicializações do servidor.
   - A cada emissão concluída, a tabela de registros é exportada/atualizada para a planilha Excel (`livro_registro_certificados.xlsx`).

2. **Validação Pública em Nuvem com Proteção de Administrador**:
   - Conforme ADR-0002 e ADR-0003, a aplicação no Streamlit funciona com separação de visões:
     - **Página Pública (QR Code / Raiz)**: Ao acessar `/?validar=<codigo>` ou a raiz sem autenticação, exibe exclusivamente a consulta de autenticidade, com selo oficial e dados do curso, aplicando mascaramento de CPF (`***.123.456-**`) em total conformidade com a LGPD e sem download do PDF original (mitigando riscos de falsificação e vazamento de dados pessoais).
     - **Painel de Emissão (Administrador)**: O painel de upload de planilha, configuração da turma e emissão massiva de certificados fica protegido por senha mestre do emissor.
   - O campo "URL Base de Validação" na interface permite configurar o domínio público oficial (ex: `https://certificados-futurofacil.streamlit.app/?validar=`), que é gravado no QR Code impresso no verso.

3. **Motor de Renderização em PDF (ReportLab)**:
   - Uso da biblioteca ReportLab para renderização direta em PDF vetorial de alta definição no formato A4 Paisagem (841.89 x 595.27 pt).
   - O Guilloché é sintetizado matematicamente através de equações paramétricas sinusoidais superpostas geradas diretamente no canvas vetorial, com espessuras micrométricas e cores parametrizáveis.
   - Logotipos em SVG são convertidos e renderizados com máxima nitidez vetorial via `svglib`.

4. **Regras de Paginação e Arquitetura Duplex**:
   - Configuração padrão: 1 registro por folha e virada de livro a cada 100 folhas.
   - O gerador de PDF consolidado cria um único documento contendo todas as páginas intercaladas sequencialmente em duplex (Página 1: Frente Aluno 1; Página 2: Verso Aluno 1; Página 3: Frente Aluno 2; Página 4: Verso Aluno 2...).

5. **Entrada de Dados e Higienização**:
   - A planilha de entrada exige estritamente duas colunas: `Nome` e `CPF`.
   - Limpeza e validação de CPF: remoção de caracteres não numéricos, verificação de 11 dígitos e cálculo dos dígitos verificadores.
   - Nomes recebem tratamento de caixa alta/baixa inteligente (Title Case com tratamento de preposições como "de", "da", "do").

6. **Desacoplamento Arquitetural**:
   - O núcleo (`core/`) não possui nenhuma dependência do Streamlit, permitindo ser invocado tanto pela interface web (`app.py`) quanto pelo utilitário de linha de comando (`cli.py`) e por baterias de testes unitários.

## Testing Decisions

1. **Diretrizes de Qualidade dos Testes**:
   - Testar exclusivamente o comportamento externo observável e contratos de saída, nunca detalhes de implementação privada ou chamadas intermediárias de desenho.
   - Ausência de mocks para operações com arquivos: testes devem gerar arquivos reais em diretórios temporários (`tmp_path`) e validar suas propriedades reais (integridade do PDF, páginas, integridade do Excel, tabelas SQLite).

2. **Costuras de Teste Prioritárias**:
   - **Costura do Motor de Emissão**: Invoca a função orquestradora central passando um lote com alunos válidos e inválidos. Verifica se os registros são inseridos com sequenciamento correto no SQLite/banco, se os PDFs são gerados com o número esperado de páginas duplex, se o Excel mestre reflete os dados e se o ZIP contém os arquivos corretos.
   - **Costura da Validação Pública**: Invoca a função de consulta passando códigos válidos e inválidos. Verifica se o mascaramento LGPD do CPF é rigorosamente respeitado e se códigos inexistentes retornam status de não encontrado de forma segura.
   - **Testes Unitários Específicos**:
     - Validação e higienização de CPF (casos válidos, dígitos incorretos, tamanho inadequado).
     - Conversão de carga horária para extenso em português (`num2words`).
     - Fórmulas de cálculo de livro e folha na virada do centésimo registro.

## Out of Scope

- Envio automatizado de e-mails/SMTP direto para os alunos (delegado para uma fase/ticket futuro).
- Integração com gateways de pagamento ou controle de mensalidades.
- Assinatura digital padrão ICP-Brasil com token A3 físico (o respaldo jurídico adotado é o da MP 2.200-2/2001 art. 10 §2º, viabilizado por hash SHA-256 e QR Code de verificação pública).

## Further Notes

- A especificação atende integralmente ao vocabulário canônico definido em `CONTEXT.md` e aos registros de decisão arquitetural `ADR-0001`, `ADR-0002` e `ADR-0003`.
