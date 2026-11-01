# Sources tarifaires embarquées

Extraction vérifiée le 21 juillet 2026. Aucune de ces sources n’est appelée à l’exécution du module.

| Jeu de données | Couverture utilisée | Empreinte SHA-256 du fichier téléchargé |
|---|---|---|
| CRE — `Tarifs Métropole S21 - T19.xlsx` | S21 du 9 octobre 2021 au barème ouvert le 5 juin 2026 | `5fb83f3d4ba59fc040c25e51adf6ca9e2e6592e4a4b59a90fe8132dca4350876` |
| CRE — `Option_Base.csv` | composante énergie TTC applicable du 1er janvier 2021 au barème ouvert le 1er février 2026 | `51df3b89a5ebf4fdefab782a696e37b210f595ecbabb465a41699c80a5e1eaf5` |
| CRE — `Option_HPHC.csv` | composante heures pleines TTC applicable du 1er janvier 2021 au barème ouvert le 1er février 2026 | `c53c8612ea26d150eafb2bae54ea2223134050f13b073dcb0b9340d633aacd9f` |

Les barèmes TRVE combinent les deux fichiers. Leur champ `source_hash` contient donc les deux empreintes SHA-256 concaténées, dans l’ordre Base puis HPHC, sans séparateur.

La ligne `TRVE-2021-01` reprend uniquement la partie comprise entre le 1er et le 31 janvier 2021 du barème source officiel allant du 1er août 2020 au 31 janvier 2021. Cette troncature garantit une couverture sans trou à partir de la date d’historique demandée, sans importer de période antérieure à 2021.

Sources :

- <https://www.cre.fr/documents/open-data/arretes-tarifaires-photovoltaiques-en-metropole.html>
- <https://www.legifrance.gouv.fr/jorf/article_jo/JORFARTI000044173104>
- <https://www.data.gouv.fr/datasets/historique-des-tarifs-reglementes-de-vente-delectricite-pour-les-consommateurs-residentiels>
- <https://www.legifrance.gouv.fr/jorf/id/JORFTEXT000051993896> — gamme fonctionnelle du Tarif Jaune : multiples de 1 kVA à partir de 37 kVA ;
- <https://www.edf.fr/collectivites/offres-electricite-et-gaz/electricite/tarifs-reglementes/tarif-jaune> — plafond de 250 kVA.

Les deux dernières références définissent les puissances proposées par l’interface. Elles ne constituent pas un historique simplifié des prix Jaunes embarqué : les grilles Jaunes sont horosaisonnières et ne sont pas réduites silencieusement à un prix unique par le module.

La période mai–juillet 2022 possède deux cas officiels S21. Les deux sont conservés. Le résolveur choisit le dernier barème officiel inséré, donc le cas B, tout en laissant le cas A visible et duplicable dans l’administration.

## Contrôle indicatif de raccordement — vérification du 12 octobre 2026

Lecture de la documentation Enedis-MOP-RAC_045E, version 1 du 15 septembre 2025, remplaçant Enedis-FOR-RAC_22E version 10 : [demande de raccordement Enedis](https://www.enedis.fr/sites/default/files/documents/pdf/Enedis-FOR-RAC_22E.pdf). La rubrique E2 distingue Pmax (minimum puissance-crête / puissance nominale onduleurs) et Pracc (puissance maximale demandée en injection). Le renvoi 33 limite Pracc à 6 kVA en monophasé ; le renvoi 35 rappelle 12 kVA par phase et 6 kVA de déséquilibre en triphasé jusqu’à 36 kVA. Ces règles ne sont pas un plafond de tous les contrats Tarif Bleu.

Il s’agit d’une lecture documentaire, pas d’une validation technique du dispositif de bridage ni d’un accord Enedis. La limite saisie sert au contrôle indicatif et n’applique aucun réglage aux onduleurs. Les calculs annuels ne disposent pas du profil temporel permettant de quantifier l’écrêtement. Aucun appel réseau n’est ajouté à l’exécution.
