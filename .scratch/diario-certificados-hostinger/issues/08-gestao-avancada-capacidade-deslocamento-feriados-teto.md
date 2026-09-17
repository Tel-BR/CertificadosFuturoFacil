# 08: Gestão Avançada de Capacidade — Deslocamento fora de Goiânia, Feriados Nacionais, Remarcação e Teto de 80h

**What to build:**
Refinar a gestão estratégica de capacidade da agenda da Futuro Fácil para turmas corporativas presenciais e híbridas fora de Goiânia:
1. **Deslocamento / Viagem:** Adicionar toggles na turma para bloquear antes (ida), depois (volta) ou ambos, com janela padrão de 1 dia antes/depois no turno selecionado (`M`, `V`, `N` ou `D`), podendo ocupar finais de semana (domingo para ida, sábado para volta). O bloqueio de deslocamento previne choques de horário no Calendário (com badge `✈`), mas é estritamente logístico: não entra na lista de chamada nem na carga horária pedagógica dos certificados.
2. **Motor de Adiamento / Remarcação em Bloco:** Permitir remarcar uma turma informando a nova data de início, projetando e movendo atomicamente todos os encontros de aula e seus deslocamentos associados para as novas datas (com suporte adicional a ajustes finos encontro por encontro). A turma só deixa as datas antigas no momento exato em que as novas datas forem gravadas; se houver colisão de horário em qualquer novo dia, a operação inteira é abortada preservando a agenda original.
3. **Calendário de Feriados Nacionais e Bloqueios Pessoais:** Trazer pré-carregados os feriados nacionais oficiais destacados como datas bloqueantes por padrão, com suporte a bloqueios pessoais (férias, compromissos particulares). Permitir exceção consciente confirmada pelo instrutor caso decida ministrar aula em um feriado. Feriados em terças e quintas sugerem ponte de feriado (segunda/sexta) de forma não-intrusiva apenas se o instrutor tentar agendar aula naquela data vizinha.
4. **Teto Mensal de 80h e Aviso de Sobrecarga:** Exibir contador de carga horária mensal no calendário (ex: `64h / 80h`), emitindo aviso severo não-bloqueante ao tentar agendar ou adiar uma turma para um mês cuja soma de aulas ultrapasse 80 horas.

**Blocked by:**
03: Calendário Anual/Mensal, Rollover de Turnos e Carga de Testes Fictícios
06: Fechamento Assistido, Emissão de Certificados e Livro de Registro Digital

**Status:** done

- [x] O formulário de turmas presenciais disponibiliza opções para ativar bloqueio de deslocamento prévio (ida), posterior (volta) ou ambos, com sugestão automática quando a cidade informada for diferente de Goiânia.
- [x] O bloqueio de deslocamento permite selecionar a data e o turno (`M`, `V`, `N`, `D`), permitindo ocupar finais de semana (domingos e sábados) para viabilizar viagens logísticas.
- [x] Em turmas híbridas, os encontros presenciais fora de Goiânia recebem o vínculo dos blocos de deslocamento correspondentes.
- [x] Os registros de deslocamento são gravados na tabela `encontros` com `tipo = 'deslocamento'`, gerando bloqueio impeditivo de choque de agenda no Calendário com ícone `✈` e texto explicativo no popover, sendo estritamente isolados da lista de chamada do Modo Aula e da carga horária de certificados.
- [x] A funcionalidade "Adiar/Remarcar Turma" move em bloco todos os encontros de aula e deslocamentos vinculados para a nova data de início de forma transacional atômica, validando a ausência de choques em todas as novas datas antes de desocupar as datas antigas.
- [x] Tabela de feriados nacionais e bloqueios pessoais do instrutor impede agendamentos por padrão, mas permite confirmação explícita de exceção extraordinária consciente.
- [x] Feriados em terças e quintas sugerem ponte de feriado (segunda ou sexta) apenas quando o operador tenta agendar aula na data próxima do fim de semana.
- [x] Medidor visual de carga horária mensal (ex: `Xh / 80h`) é exibido no topo do calendário mensal e nos cartões anuais, disparando alerta enfático não-bloqueante de sobrecarga de capacidade ao agendar ou adiar turmas para um mês que ultrapasse 80 horas de aula.
