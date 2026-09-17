# 04: Modo Aula Mobile — Chamada em Tempo Real e Plano de Aula Dinâmico

**What to build:**
Permitir que o operador, em sala de aula através do celular ou notebook, realize a chamada diária e o registro pedagógico da aula em tempo real com agilidade: ao abrir o encontro, o conteúdo previsto aparece pré-digitado em campo editável para adaptação dinâmica; com um toque no botão "Marcar Todos Presentes" todos os alunos recebem presença, podendo-se desmarcar faltas pontuais em botões de toque fácil; ao salvar, o diário é persistido em transação atômica única no MariaDB e o percentual de frequência acumulada de cada aluno é recalculado imediatamente com alertas visuais de risco para quem estiver abaixo de 75%.

**Blocked by:**
02: Autenticação Administrativa e Estrutura Base do Painel Web
03: Calendário Anual/Mensal, Rollover de Turnos e Carga de Testes Fictícios

**Status:** completed

- [x] A tela `/diario/aula` abre com layout vertical responsivo otimizado para navegação móvel (botões de toque confortáveis e contraste adequado).
- [x] O campo de **Conteúdo da Aula** é pré-carregado com o conteúdo previsto cadastrado na ementa daquela turma, permitindo ao professor editá-lo e complementá-lo conforme a dinâmica real da aula, ou preenchê-lo do zero caso não haja previsão prévia.
- [x] Um botão proeminente **"Marcar Todos Presentes"** preenche a presença de todos os matriculados com um único toque.
- [x] Cada aluno possui controle binário claro e responsivo de presença (**Presente / Falta**).
- [x] A lista exibe o percentual acumulado de frequência de cada aluno em tempo real, destacando com alerta visual em destaque (amarelo/vermelho) alunos que atingirem ou caírem abaixo de 75%.
- [x] **Gravação Transacional Atômica (Costura de Teste 3):** O salvamento do diário persiste o status de todas as presenças e o texto do plano de aula dentro de uma transação única no MariaDB (`BEGIN ... COMMIT`), garantindo integridade e prevenindo gravações parciais.
- [x] O recálculo de frequência após o salvamento é instantâneo e exibe feedback de sucesso sem perda de estado na interface.
