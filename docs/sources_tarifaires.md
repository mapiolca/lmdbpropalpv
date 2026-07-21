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

La période mai–juillet 2022 possède deux cas officiels S21. Les deux sont conservés. Le résolveur choisit le dernier barème officiel inséré, donc le cas B, tout en laissant le cas A visible et duplicable dans l’administration.
