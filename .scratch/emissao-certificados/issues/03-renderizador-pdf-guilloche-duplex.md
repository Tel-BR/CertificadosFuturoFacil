# 03: Motor de renderização em PDF vetorial com Guilloché, minutas legais e consolidador duplex

**What to build:**
O motor visual de desenho em PDF A4 Paisagem (ReportLab) que gera o anverso e o reverso dos certificados com qualidade numismática (guilloché vetorial matemático paramétrico), logotipo institucional (SVG/PNG), texto de concessão formal, carga horária por extenso em português, ementa detalhada, minutas legais completas (CF/88, LDB, Decreto 5.154/2004, MP 2.200-2/2001, CC art. 219), QR Code de verificação e assinatura (digitalizada ou linha para caneta), além do consolidador que une o lote em um único PDF unificado com intercalação duplex sequencial para gráfica (Frente A, Verso A, Frente B, Verso B...).

**Blocked by:** 02: Livro de Registro Digital com persistência SQLite e exportação contínua para Excel

**Status:** ready-for-agent

- [ ] Módulo \core/guilloche.py\ gerando curvas paramétricas matemáticas vetoriais com cores customizáveis no canvas do ReportLab.
- [ ] Renderização da Frente (Anverso) A4 Paisagem com borda guilloché, logo institucional, dados do aluno, texto de concessão com carga horária por extenso e cláusula legal resumida.
- [ ] Renderização do Verso (Reverso) contendo Ementa do curso, assento formal do Livro de Registro Digital, QR Code vetorial, fundamentação jurídica completa e nota da MP 2.200-2/2001.
- [ ] Suporte à alternância entre imagem de assinatura digitalizada e linha formal em branco para assinatura física.
- [ ] Módulo consolidador que gera o PDF unificado com duplex intercalado ordenado para gráfica rápida.
- [ ] Testes automatizados verificando dimensões dos PDFs (A4 paisagem), integridade visual vetorial e número exato de páginas duplex geradas.
