# 03: Motor de renderização em PDF vetorial com Guilloché, minutas legais e consolidador duplex

**What to build:**
O motor visual de desenho em PDF A4 Paisagem (ReportLab) que gera o anverso e o reverso dos certificados com qualidade numismática (guilloché vetorial matemático paramétrico), logotipo institucional (SVG/PNG), texto de concessão formal, carga horária por extenso em português, ementa detalhada, minutas legais completas (CF/88, LDB, Decreto 5.154/2004, MP 2.200-2/2001, CC art. 219), QR Code de verificação e assinatura (digitalizada ou linha para caneta), além do consolidador que une o lote em um único PDF unificado com intercalação duplex sequencial para gráfica (Frente A, Verso A, Frente B, Verso B...).

**Blocked by:** 02: Livro de Registro Digital com persistência SQLite e exportação contínua para Excel

**Status:** resolved

- [x] Módulo `core/guilloche.py` gerando curvas paramétricas matemáticas vetoriais com cores customizáveis no canvas do ReportLab.
- [x] Renderização da Frente (Anverso) A4 Paisagem com borda guilloché, logo institucional, dados do aluno, texto de concessão com carga horária por extenso e cláusula legal resumida.
- [x] Renderização do Verso (Reverso) contendo Ementa do curso, assento formal do Livro de Registro Digital, QR Code vetorial, fundamentação jurídica completa e nota da MP 2.200-2/2001.
- [x] Suporte à alternância entre imagem de assinatura digitalizada e linha formal em branco para assinatura física.
- [x] Módulo consolidador que gera o PDF unificado com duplex intercalado ordenado para gráfica rápida.
- [x] Testes automatizados verificando dimensões dos PDFs (A4 paisagem), integridade visual vetorial e número exato de páginas duplex geradas.

## Comments

### Plano e Decisões de Design (Rodadas de Alinhamento)
- **Identidade e Posicionamento**: Marca `FUTUROFÁCIL` em caixa alta sem espaço, com a tagline oficial `CAPACITAÇÃO DIGITAL SOB MEDIDA` (sem monograma FF).
- **Paleta Oficial**: Primária Petróleo Tech (`#0E7490`) e Acento Coral Solar (`#EA580C`).
- **Tipografia**: `Saira Condensed` para a marca (contraste entre FUTURO fino e FÁCIL pesado) e corpo/títulos do certificado; `Ubuntu Mono` para assento de registro e hash SHA-256.
- **Símbolo**: Slot modular vetorial circular (1:1), mantendo a frente em aberto para refino futuro.
- **Padrão Visual**: Guilloché generativo de ondas harmônicas contínuas perimétricas (*wavefields* e selo numismático contemporâneo), sem arcaísmos cartoriais.

## Answer

Ticket 03 implementado integralmente seguindo o ciclo TDD (Red -> Green):
- Criado `core/guilloche.py` com equações paramétricas sinusoidais compostas para fitas de ondas perimétricas (`draw_modern_wave_ribbon`), selo numismático contemporâneo (`draw_security_seal`) e moldura de segurança completa (`draw_guilloche_frame`).
- Criado `core/renderer.py` com renderização vetorial de anverso e reverso A4 Paisagem (841.89 x 595.27 pt):
  - Inclusão das fontes TrueType oficiais `SairaCondensed` e `UbuntuMono` em `assets/fonts/` com fallback para fontes nativas.
  - Anverso com `FUTUROFÁCIL`, assento do livro digital, concessão com carga horária por extenso em português, cláusula legal resumida e alternância de assinatura.
  - Reverso em grid modular com Ementa formatada via `Paragraph`, QR Code de validação eletrônica, hash SHA-256 e fundamentação jurídica integral (CF/88, LDB art. 42, Dec. 5.154/2004, CC art. 219 e MP 2.200-2/2001).
- Criado `core/consolidator.py` provendo `consolidate_duplex_pdf` com intercalação rigorosa de $2 \times N$ páginas duplex para gráfica e `merge_duplex_files` via `pypdf`.
- Exportação dos componentes em `core/__init__.py`.
- 11 novos testes automatizados adicionados (`tests/test_guilloche.py`, `tests/test_renderer.py`, `tests/test_consolidator.py`).
- 41 testes da suíte completa executados e aprovados com 100% de sucesso via `pytest`.
