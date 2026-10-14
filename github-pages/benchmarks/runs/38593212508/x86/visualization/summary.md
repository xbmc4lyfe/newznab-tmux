## Native decoder speedup estimates

Run result: `useful_improvement`.

Fixture comparison only. Inconclusive estimates do not establish a gain. Profiles do not supply timings.

| Stratum | Decision | C++ / Rust | 95% interval | Time saved | Eligible / invalid |
|---|---|---:|---|---:|---|
| 4096 B / ordinary / tolerant | useful_improvement | 1.154× | 1.129–1.193× | 13.32% | 31 / 0 |
| 4096 B / ordinary / strict | useful_improvement | 1.146× | 1.101–1.182× | 12.73% | 31 / 0 |
| 4096 B / ordinary / body | useful_improvement | 1.091× | 1.068–1.120× | 8.31% | 31 / 0 |
| 4096 B / heavy / tolerant | useful_improvement | 1.214× | 1.196–1.246× | 17.63% | 31 / 0 |
| 4096 B / heavy / strict | useful_improvement | 1.193× | 1.173–1.209× | 16.16% | 31 / 0 |
| 4096 B / heavy / body | useful_improvement | 1.106× | 1.086–1.119× | 9.60% | 31 / 0 |
| 524288 B / ordinary / tolerant | useful_improvement | 1.299× | 1.268–1.353× | 23.00% | 31 / 0 |
| 524288 B / ordinary / strict | useful_improvement | 1.310× | 1.286–1.399× | 23.67% | 31 / 0 |
| 524288 B / ordinary / body | useful_improvement | 1.163× | 1.122–1.188× | 14.00% | 31 / 0 |
| 524288 B / heavy / tolerant | useful_improvement | 1.537× | 1.478–1.559× | 34.92% | 31 / 0 |
| 524288 B / heavy / strict | useful_improvement | 1.554× | 1.479–1.576× | 35.64% | 31 / 0 |
| 524288 B / heavy / body | useful_improvement | 1.125× | 1.109–1.147× | 11.15% | 31 / 0 |
| 1048576 B / ordinary / tolerant | useful_improvement | 1.490× | 1.421–1.518× | 32.88% | 31 / 0 |
| 1048576 B / ordinary / strict | useful_improvement | 1.464× | 1.431–1.541× | 31.69% | 31 / 0 |
| 1048576 B / ordinary / body | useful_improvement | 1.115× | 1.087–1.135× | 10.28% | 31 / 0 |
| 1048576 B / heavy / tolerant | useful_improvement | 1.441× | 1.429–1.469× | 30.59% | 31 / 0 |
| 1048576 B / heavy / strict | useful_improvement | 1.455× | 1.442–1.475× | 31.26% | 31 / 0 |
| 1048576 B / heavy / body | useful_improvement | 1.097× | 1.086–1.126× | 8.82% | 31 / 0 |
| 8388608 B / ordinary / tolerant | useful_improvement | 1.477× | 1.462–1.489× | 32.29% | 31 / 0 |
| 8388608 B / ordinary / strict | useful_improvement | 1.513× | 1.497–1.552× | 33.90% | 31 / 0 |
| 8388608 B / ordinary / body | useful_improvement | 1.066× | 1.059–1.080× | 6.18% | 31 / 0 |
| 8388608 B / heavy / tolerant | useful_improvement | 1.509× | 1.490–1.521× | 33.75% | 31 / 0 |
| 8388608 B / heavy / strict | useful_improvement | 1.540× | 1.519–1.557× | 35.08% | 31 / 0 |
| 8388608 B / heavy / body | useful_improvement | 1.072× | 1.056–1.079× | 6.69% | 31 / 0 |
