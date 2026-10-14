## Native decoder speedup estimates

Run result: `inconclusive`.

Fixture comparison only. Inconclusive estimates do not establish a gain. Profiles do not supply timings.

| Stratum | Decision | C++ / Rust | 95% interval | Time saved | Eligible / invalid |
|---|---|---:|---|---:|---|
| 4096 B / ordinary / tolerant | useful_improvement | 1.125× | 1.081–1.167× | 11.08% | 31 / 0 |
| 4096 B / ordinary / strict | inconclusive | 1.089× | 1.020–1.138× | 8.18% | 31 / 0 |
| 4096 B / ordinary / body | inconclusive | 1.105× | 1.020–1.139× | 9.51% | 31 / 0 |
| 4096 B / heavy / tolerant | useful_improvement | 1.318× | 1.263–1.383× | 24.14% | 31 / 0 |
| 4096 B / heavy / strict | useful_improvement | 1.253× | 1.191–1.312× | 20.22% | 31 / 0 |
| 4096 B / heavy / body | useful_improvement | 1.159× | 1.113–1.187× | 13.72% | 31 / 0 |
| 524288 B / ordinary / tolerant | useful_improvement | 1.496× | 1.465–1.560× | 33.14% | 31 / 0 |
| 524288 B / ordinary / strict | useful_improvement | 1.461× | 1.429–1.516× | 31.55% | 31 / 0 |
| 524288 B / ordinary / body | useful_improvement | 1.218× | 1.212–1.234× | 17.87% | 31 / 0 |
| 524288 B / heavy / tolerant | useful_improvement | 1.939× | 1.891–1.989× | 48.42% | 31 / 0 |
| 524288 B / heavy / strict | useful_improvement | 1.915× | 1.874–1.950× | 47.78% | 31 / 0 |
| 524288 B / heavy / body | useful_improvement | 1.248× | 1.234–1.263× | 19.89% | 31 / 0 |
| 1048576 B / ordinary / tolerant | useful_improvement | 1.487× | 1.467–1.508× | 32.77% | 31 / 0 |
| 1048576 B / ordinary / strict | useful_improvement | 1.465× | 1.435–1.492× | 31.72% | 31 / 0 |
| 1048576 B / ordinary / body | useful_improvement | 1.168× | 1.151–1.175× | 14.39% | 31 / 0 |
| 1048576 B / heavy / tolerant | useful_improvement | 1.850× | 1.828–1.872× | 45.95% | 31 / 0 |
| 1048576 B / heavy / strict | useful_improvement | 1.820× | 1.783–1.849× | 45.06% | 31 / 0 |
| 1048576 B / heavy / body | useful_improvement | 1.203× | 1.190–1.214× | 16.90% | 31 / 0 |
| 8388608 B / ordinary / tolerant | useful_improvement | 1.501× | 1.479–1.522× | 33.37% | 31 / 0 |
| 8388608 B / ordinary / strict | useful_improvement | 1.477× | 1.443–1.519× | 32.32% | 31 / 0 |
| 8388608 B / ordinary / body | useful_improvement | 1.131× | 1.124–1.144× | 11.55% | 31 / 0 |
| 8388608 B / heavy / tolerant | useful_improvement | 1.859× | 1.840–1.886× | 46.22% | 31 / 0 |
| 8388608 B / heavy / strict | useful_improvement | 1.814× | 1.791–1.846× | 44.86% | 31 / 0 |
| 8388608 B / heavy / body | useful_improvement | 1.180× | 1.163–1.206× | 15.23% | 31 / 0 |
