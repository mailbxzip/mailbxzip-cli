# Exemples de configuration

Chaque fichier `.ini` de ce dossier décrit **une boîte à archiver**. Pour
l'utiliser, copiez-le dans votre dossier de configuration, renommez-le et
remplacez les valeurs.

```bash
mkdir -p ~/.config/mailbxzip/config
cp examples/archive-2-ans-html.ini ~/.config/mailbxzip/config/mon-archive
$EDITOR ~/.config/mailbxzip/config/mon-archive

cd cli && php cli.php mailbox --start mon-archive
```

> Le nom du fichier de configuration est l'argument de la commande. Il n'a pas
> d'extension une fois en place : `--start mon-archive` lit
> `~/.config/mailbxzip/config/mon-archive`.

L'archive est écrite dans `~/.config/mailbxzip/archives/<address>/`, puis
compressée en `~/.config/mailbxzip/archives/<nom-de-la-config>.zip`.

---

## Les exemples

| Fichier | Ce qu'il fait | Modifie la boîte ? |
|---|---|---|
| [`essai-hors-ligne.ini`](essai-hors-ligne.ini) | Exporte un jeu de messages fictifs en HTML. Ni serveur ni compte requis. | Non |
| [`imap-simple.ini`](imap-simple.ini) | **Point de départ pour une vraie boîte** : IMAP vers `.eml` ou HTML, avec ou sans suppression. | Non par défaut |
| [`archive-2-ans-html.ini`](archive-2-ans-html.ini) | Archive en HTML les messages de **plus de deux ans**. | **Non** |
| [`archive-2-ans-html-corbeille.ini`](archive-2-ans-html-corbeille.ini) | Idem, **puis déplace les messages archivés dans la corbeille**. | Oui, réversible |
| [`archive-2-ans-html-purge.ini`](archive-2-ans-html-purge.ini) | Idem, **puis efface définitivement** les messages archivés. | **Oui, définitivement** |
| [`archive-complete-pdf.ini`](archive-complete-pdf.ini) | Archive toute la boîte en PDF, pièces jointes embarquées. | Non |
| [`archive-exercice-mbox.ini`](archive-exercice-mbox.ini) | Archive une année civile au format mbox réimportable. | Non |

### `essai-hors-ligne.ini`

Le point de départ conseillé. Le connecteur d'entrée `Test` fournit une petite
boîte fictive — dossiers imbriqués, accents, pièces jointes, message sans date —
ce qui permet de voir à quoi ressemble une archive avant de brancher un vrai
compte.

### `imap-simple.ini`

Le socle pour une vraie boîte : la configuration IMAP minimale, sans fenêtre
de dates ni suppression. Les variantes sont présentes en commentaire, il
suffit de décommenter :

| Objectif | À décommenter |
|---|---|
| Archive navigable au navigateur | `out = Html` (au lieu de `out = Eml`) |
| Messages archivés vers la corbeille | `trash = 1` |
| Messages archivés effacés définitivement | `delete = 1` |
| Ne prendre que les messages anciens | `before = "-2 years"` |

Les autres exemples de ce dossier sont des variantes préréglées de celui-ci.

### `archive-2-ans-html.ini`

Le cas courant : **désengorger une boîte sans rien perdre**. Les messages de
plus de deux ans sont exportés en pages HTML navigables ; la boîte n'est pas
touchée.

La bascule tient à une ligne :

```ini
before = "-2 years"
```

`-2 years` est une date **relative, recalculée à chaque exécution**. Dans une
tâche planifiée, la fenêtre glisse donc toute seule. Une date fixe
(`before = "2023-01-01"`) est acceptée de la même façon.

Le filtre porte sur la **date d'envoi** du message (en-tête `Date:`), pas sur
sa date de réception par le serveur. C'est ce qui garde le filtre cohérent avec
le nommage des fichiers, bâti sur cette même en-tête ; sur une boîte migrée
d'un hébergeur à l'autre, la date de réception vaudrait la date de migration et
tout le courrier ancien paraîtrait récent.

Lancez cette configuration **avant** celle qui purge, et ouvrez l'archive.

### `archive-2-ans-html-corbeille.ini`

La même chose, plus `trash = 1` : les messages archivés
**quittent leurs dossiers pour la corbeille du serveur**, où ils restent
récupérables tant qu'elle n'est pas vidée.

C'est la variante à préférer pour une première purge : elle libère la boîte
tout en gardant un filet. Voir la section suivante.

### `archive-2-ans-html-purge.ini`

La même chose avec `delete = 1` seul : les messages archivés sont **effacés
définitivement**, sans passer par la corbeille. Voir la section suivante avant
de l'utiliser.

### `archive-complete-pdf.ini`

Export exhaustif en PDF, un document par message, **pièces jointes embarquées
dans le document** — un seul fichier autoportant par e-mail. Adapté à une
conservation probatoire ou à une remise à un tiers.

Le connecteur PDF requiert l'extension PHP `gd`.

### `archive-exercice-mbox.ini`

Format mbox, un fichier par dossier, **réimportable dans un client de
messagerie**. Utile pour une migration ou pour reconsulter une période dans
Thunderbird.

L'exemple montre les deux bornes ensemble :

```ini
since  = "2023-01-01"   ; incluse
before = "2024-01-01"   ; exclue
```

`since` est **inclusive**, `before` est **exclusive** : cet intervalle couvre
donc exactement l'année 2023, du 1er janvier au 31 décembre.

---

## Purger la boîte après archivage

`delete = 1` supprime des messages **définitivement**. La commande ne demande
aucune confirmation.

**Procédure recommandée**

1. Lancez d'abord la configuration **sans** `delete`.
2. Ouvrez l'archive et vérifiez son contenu — le nombre de messages, les
   dossiers, quelques documents au hasard.
3. Sauvegardez le fichier `.zip` ailleurs que sur la machine qui l'a produit.
4. Ajoutez seulement ensuite `delete = 1` et relancez. Les messages déjà
   archivés ne sont pas retéléchargés, ils sont simplement supprimés.

### Ce que l'outil garantit

- **La suppression est la toute dernière étape**, après l'écriture de
  l'archive ZIP. Si le ZIP n'a pas pu être produit, rien n'est supprimé et
  l'incident est journalisé.
- **Seuls les messages effectivement archivés** sont supprimés, d'après le
  journal `saved_emails.json`. Un message hors fenêtre de dates, ou dont
  l'écriture a échoué, reste sur le serveur.
- **Les deux connecteurs doivent y consentir.** La source doit savoir
  supprimer, et le format de sortie doit se porter garant de son archive. Une
  combinaison non autorisée s'arrête avec un message explicite plutôt que de
  supprimer :

  ```
  'delete = 1' was asked for, but the output connector
  Mailbxzip\Cli\Out\Test does not vouch for its archive:
  refusing to empty the source.
  ```

- **Rien n'est supprimé deux fois** : `purged_emails.json` retient ce qui a
  déjà été purgé.

### Passer par la corbeille

`delete = 1` **efface définitivement**. `trash` déplace au lieu de détruire,
et **se suffit à lui-même** : demander la corbeille, c'est déjà demander que
les messages quittent leurs dossiers.

```ini
trash = 1                   ; corbeille trouvée toute seule
```

```ini
trash = "INBOX/Corbeille"   ; ou nommée explicitement
```

`delete = 1` reste accepté à côté, sans effet supplémentaire.

Pour voir les dossiers du compte et savoir laquelle sera retenue :

```bash
cd cli && php cli.php folders mon-archive
```

Les noms voyagent encodés sur le réseau — `Éléments supprimés` arrive comme
`&AMk-l&AOk-ments supprim&AOk-s` — donc impossibles à deviner. La commande
affiche le nom lisible et l'identifiant brut ; `trash` accepte les deux.

Avec `trash = 1`, l'outil demande au serveur quel dossier est sa corbeille —
la plupart la désignent eux-mêmes, par l'attribut `\Trash` de la norme
SPECIAL-USE. À défaut, il reconnaît les noms usuels : `Trash`, `Corbeille`,
`Deleted Items`, `Deleted Messages`, `Papierkorb`, `Cestino`, `Papelera`...

Le nom explicite accepte les deux séparateurs : `INBOX/Corbeille` comme
`INBOX.Corbeille`.

Trois points de sûreté :

- **Si la corbeille est introuvable, rien n'est supprimé.** L'export s'arrête
  sur un message explicite plutôt que de se rabattre sur un effacement
  définitif — c'est l'inverse de ce que vous auriez demandé. Le message
  **énumère les dossiers disponibles**, de quoi corriger sans chercher.
- **Si la copie vers la corbeille échoue, rien n'est effacé** du dossier
  d'origine. Le message reste à sa place et sera retenté à l'exécution
  suivante.
- **Un message déjà dans la corbeille** est effacé sur place : l'y déplacer
  n'aurait pas de sens.
- **Sur un gros dossier, le travail est découpé en lots.** Si un lot échoue,
  les autres passent quand même, et seuls les messages réellement partis sont
  notés comme purgés : le reste est réessayé à l'exécution suivante.

**Vérifiez ce qui a été retenu.** Le journal l'indique à chaque purge :

```
[INFO] trash resolved to 'Corbeille' (INBOX.Corbeille) -- the server declares it as its trash
```

Si la mention est `GUESSED FROM ITS NAME`, le serveur n'a rien déclaré et le
choix repose sur le seul nom du dossier. Sur une boîte qui possède à la fois
un `Trash` résiduel et une vraie `Éléments supprimés`, la devinette peut tomber
sur le mauvais. Nommez alors la bonne :

```ini
trash = "Éléments supprimés"
```

Si la corbeille retenue refuse les messages, **la purge s'arrête net** au
premier refus, avec les mots du serveur : elle sert tous les dossiers, insister
ne ferait qu'empiler la même erreur.

### Quand la boîte est pleine

Déplacer un message demande normalement au serveur de le détenir deux fois,
ne serait-ce qu'un instant : `MOVE` l'évite, mais tous les serveurs ne le
proposent pas, et `COPY` l'impose. Une boîte arrivée à son quota refuse donc
le déplacement — précisément la situation où l'on archive.

```ini
trash      = 1
trash_mode = "append"
```

Ce mode inverse l'ordre : chaque message est **lu, effacé, puis redéposé**
dans la corbeille. C'est l'effacement qui libère la place dont le dépôt a
besoin, donc la seule séquence qu'une boîte saturée accepte.

**Ce qu'il en coûte, à lire avant de l'activer :**

- **C'est lent.** Un message à la fois, quatre échanges chacun. Comptez une
  dizaine de minutes pour quelques milliers de messages.
- **Une fenêtre d'un message.** Entre l'effacement et le dépôt, l'e-mail n'est
  plus sur le serveur et pas encore dans la corbeille. Il n'est jamais perdu :
  la purge ne démarre qu'une fois l'archive ZIP écrite, il est donc sur disque
  pendant tout ce temps.
- **Un dépôt refusé arrête tout**, et le journal nomme le message concerné :
  celui-là ne sera que dans l'archive. Un seul, jamais davantage.
- Le message est redéposé **marqué comme lu**, avec sa date d'origine — sans
  quoi la corbeille afficherait des milliers de non-lus datés d'aujourd'hui.

> **La corbeille ne libère pas d'espace.** Elle compte dans le quota sur la
> plupart des serveurs : c'est en la vidant que vous récupérerez la place.
> Si l'archive vous suffit comme filet, `delete = 1` efface directement,
> n'exige aucune place libre, et va bien plus vite.

> Sur le connecteur déprécié `ImapLegacy`, la détection ne peut pas s'appuyer
> sur `\Trash` et se limite aux noms usuels. Nommez la corbeille explicitement.

### Ce que l'outil ne garantit pas

- **Sans `trash`, il n'y a pas de filet.** Les messages sont marqués `\Deleted`
  puis expurgés, sans transiter par un dossier de récupération.
- **Aucune vérification du contenu de l'archive.** L'outil s'assure que le
  fichier a été écrit, pas qu'il est lisible. D'où l'étape 2 ci-dessus.

---

## Aide-mémoire des clés

### Toujours nécessaires

| Clé | Rôle |
|---|---|
| `address` | Adresse de la boîte. Sert de nom au dossier d'archive, et distingue les messages reçus des messages envoyés dans le nommage des fichiers. |
| `in` | Connecteur d'entrée : `Imap`, `ImapLegacy`, `Test`. |
| `out` | Connecteur de sortie : `Html`, `Pdf`, `Eml`, `Mbox`, `Test`. |

### Connexion IMAP (`in = Imap`)

Deux écritures possibles, au choix.

```ini
host = "imap.exemple.fr"
port = 993
encryption = "ssl"        ; ssl (défaut), tls, starttls ou none
validate_cert = 1
```

```ini
; Ancienne chaîne ext-imap, toujours comprise
server = "{imap.exemple.fr:993/imap/ssl}"
```

Plus `username` et `password` dans les deux cas.

### Optionnelles

| Clé | Rôle |
|---|---|
| `since` | N'archiver que les messages envoyés **à partir de** cette date, incluse. |
| `before` | N'archiver que les messages envoyés **strictement avant** cette date. Accepte le relatif : `-2 years`, `-18 months`. |
| `wSource` | `1` conserve le `.eml` d'origine à côté du format choisi. |
| `delete` | `1` supprime de la source les messages archivés. **Destructif.** |
| `trash` | `1` déplace les messages archivés vers la corbeille au lieu de les effacer, ou nommez le dossier (`"INBOX/Corbeille"`). Se suffit à lui-même. |
| `trash_mode` | `auto` (défaut) déplace avec `MOVE`, ou `COPY` à défaut. `append` efface puis redépose chaque message : plus lent, mais seul mode qui passe sur une boîte pleine. |
| `debugHtml` | `1` conserve le HTML intermédiaire à côté de chaque PDF, pour investiguer une mise en page. |
| `archives_dir` / `tmp_dir` | Déplacent les répertoires de travail. |

L'aide générée liste les clés de chaque connecteur installé :

```bash
cd cli && php cli.php help config
```

---

## Reprendre un export interrompu

Relancez simplement la même commande. Les messages déjà écrits sont recensés
dans `saved_emails.json` et ne sont pas retéléchargés — utile sur une grosse
boîte, une connexion instable, ou un traitement étalé sur plusieurs nuits.

Élargir la fenêtre de dates et relancer n'importe que le complément.
**Rétrécir la fenêtre ne supprime rien** de ce qui est déjà archivé : une
archive ne fait que s'enrichir.

---

## Mot de passe

Les exemples portent le mot de passe en clair dans le fichier, ce qui est
**commode pour un essai mais déconseillé en exploitation** : le fichier de
configuration est relu et réécrit par l'outil à chaque message.

À défaut de mieux pour l'instant, restreignez au moins les droits :

```bash
chmod 600 ~/.config/mailbxzip/config/mon-archive
```

Sortir les identifiants du fichier de configuration est prévu — voir le lot
`L0.3` de la [feuille de route](../ROADMAP.md).

---

## Journal et suivi

Chaque export écrit `export.log` à la racine de son archive : horodatage,
progression, estimation du temps restant, et tout incident. Les avertissements
et erreurs y sont préfixés `[WARNING]` et `[ERROR]` — c'est le premier endroit
à consulter si un message manque à l'appel.

```bash
tail -f ~/.config/mailbxzip/archives/utilisateur@exemple.fr/export.log
```
