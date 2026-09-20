# 14: Intervalo entre encontros e cálculo fiel da carga horária

**Type:** task

**Status:** completed

**Contexto:**

A planilha oficial `turmas-oficiais.xlsx` contém, para cada compromisso, horário de início, término e intervalo. O modelo atual de `encontros` persiste `horario_inicio`, `horario_fim` e `turno`, mas não persiste o intervalo. Como consequência, uma aula de 08:00 a 17:00 com uma hora de intervalo pode ser apurada como 9h, quando a carga pedagógica real é 8h.

**Problema:**

A ausência do intervalo impede a importação fiel da agenda oficial e afeta a carga horária da turma, o calendário de capacidade, o faturamento/NFS-e e a informação exibida no diário.

**Critérios de aceite:**

- [x] `encontros` possui `intervalo_minutos` inteiro, não negativo e com padrão `0`.
- [x] Criação, edição, importação e exportação Excel preservam o intervalo de cada encontro.
- [x] O cálculo de horas reais exclui deslocamentos e usa `horario_fim - horario_inicio - intervalo_minutos`.
- [x] A carga exibida da turma, o teto mensal de 80h e a apuração de faturamento/NFS-e usam a mesma regra.
- [x] Encontros já existentes permanecem semanticamente inalterados com `intervalo_minutos = 0` até ajuste explícito.
- [x] Testes cobrem encontro integral de 08:00 a 17:00 com 60 minutos de intervalo, totalizando 8h.

**Impacto da importação de turmas oficiais:**

Não carregar a coluna `Intervalo` enquanto este ticket não estiver entregue. Carregar início, término e turno é seguro, mas declarar a carga total como oficial sem o intervalo não é.

## Comments

- 2026-09-18: Achado durante a análise da planilha de compromissos oficiais para carga no staging. O horário já é persistido por encontro; o intervalo é o atributo ausente.
- 2026-09-18: Implementado e aprovado em testes de criação, edição, sincronização Excel, faturamento, capacidade e formulário.
- 2026-09-18: Publicado no staging junto à carga oficial; 115 instruções SQL executadas sem erro (17 turmas e 77 encontros).
