# mailbxzip-cli

Archiver une boîte aux lettres électronique dans une archive ZIP, en
conservant l'arborescence de ses dossiers.

*get your mailbox in a zip file*

---

## À quoi ça sert

Récupérer le contenu d'une boîte mail et le poser sur disque dans un format
qui se lit sans client de messagerie — puis, si vous le demandez, vider la
boîte de ce qui vient d'être archivé.

| Cas d'usage | |
|---|---|
| Départ d'un collaborateur | Conserver sa boîte avant de fermer le compte. |
| Désengorger une boîte pleine | Archiver les vieux messages, puis les supprimer du serveur. |
| Migration ou résiliation | Récupérer les messages avant de changer d'hébergeur. |
| Portabilité RGPD | Remettre à une personne l'export lisible de ses e-mails. |
| Conservation légale | Garder une copie hors ligne, en PDF ou en `.eml`. |

L'export est **long, interruptible et reprenable** : il journalise sa
progression et reprend là où il s'était arrêté.

---

## Prérequis

- **PHP ≥ 8.1** avec les extensions `dom`, `mbstring`, `zip`
- **Composer**
- `ext-gd` **uniquement** pour la sortie PDF (exigée par mPDF)

> `ext-imap` n'est **pas** nécessaire. PHP l'a dépréciée et sortie de son cœur
> en 8.4 ; le connecteur IMAP parle le protocole directement.

## Installation

```bash
git clone https://github.com/mailbxzip/mailbxzip-cli.git
cd mailbxzip-cli/cli
composer install
```

## Essayer en trente secondes

Sans serveur ni compte, avec un jeu de messages fictifs :

```bash
mkdir -p ~/.config/mailbxzip/config
cp ../examples/essai-hors-ligne.ini ~/.config/mailbxzip/config/essai
php cli.php mailbox --start essai
```

Ouvrez ensuite
`~/.config/mailbxzip/archives/essai@exemple.fr/index.html` dans un navigateur.

---

## Archiver une vraie boîte

```bash
cp ../examples/imap-simple.ini ~/.config/mailbxzip/config/ma-boite
$EDITOR ~/.config/mailbxzip/config/ma-boite      # adresse, serveur, identifiants
php cli.php mailbox --start ma-boite
```

La configuration minimale tient en quelques lignes :

```ini
address = "utilisateur@exemple.fr"

in  = Imap
out = Eml

host       = "imap.exemple.fr"
port       = 993
encryption = "ssl"
username   = "utilisateur@exemple.fr"
password   = "…"
```

### Les quatre variantes courantes

| Objectif | À ajouter |
|---|---|
| Fichiers `.eml` réimportables | `out = Eml` |
| Archive navigable au navigateur | `out = Html` |
| …sans toucher à la boîte | *(rien — c'est le comportement par défaut)* |
| …en envoyant les messages archivés à la corbeille | `trash = 1` |
| …en les effaçant définitivement | `delete = 1` |

[`examples/imap-simple.ini`](examples/imap-simple.ini) contient les quatre,
prêtes à décommenter. Le dossier [`examples/`](examples/README.md) en propose
six autres, chacune commentée.

---

## Configuration

Un fichier INI par boîte, dans `~/.config/mailbxzip/config/`. Le nom du fichier
est l'argument de la commande : `--start ma-boite` lit
`~/.config/mailbxzip/config/ma-boite`.

### Clés obligatoires

| Clé | Rôle |
|---|---|
| `address` | Adresse de la boîte. Nomme le dossier d'archive, et sert à distinguer vos messages envoyés de ceux reçus au moment de nommer les fichiers. |
| `in` | Connecteur d'entrée. |
| `out` | Connecteur de sortie. |

### Connexion IMAP

Deux écritures équivalentes, au choix :

```ini
host          = "imap.exemple.fr"
port          = 993
encryption    = "ssl"      ; ssl (défaut), tls, starttls ou none
validate_cert = 1
```

```ini
; Ancienne chaîne ext-imap, toujours comprise
server = "{imap.exemple.fr:993/imap/ssl}"
```

Plus `username` et `password` dans les deux cas.

### Clés optionnelles

| Clé | Rôle |
|---|---|
| `since` | N'archiver que les messages envoyés **à partir de** cette date, incluse. |
| `before` | N'archiver que les messages envoyés **strictement avant** cette date. Accepte le relatif : `-2 years`. |
| `delete` | `1` **efface** de la source les messages archivés. **Destructif.** |
| `trash` | `1` **déplace** les messages archivés vers la corbeille, ou nommez le dossier. Se suffit à lui-même. |
| `trash_mode` | `auto` (défaut) ou `append`. `append` efface puis redépose chaque message : lent, mais seul mode qui passe sur une boîte à son quota. |
| `wSource` | `1` conserve le `.eml` d'origine à côté du format choisi. |
| `debugHtml` | `1` conserve le HTML intermédiaire à côté de chaque PDF. |
| `archives_dir` / `tmp_dir` | Déplacent les répertoires de travail. |

Chaque connecteur documente ses propres clés :

```bash
php cli.php help config
```

---

## Connecteurs

L'outil est un pipeline **source → format**, les deux extrémités étant
interchangeables par configuration.

### Entrée (`in`)

| Valeur | État |
|---|---|
| `Imap` | ✅ IMAP en PHP pur, aucune extension requise. **Défaut recommandé.** |
| `Test` | ✅ Jeu de messages fictifs, pour essayer hors ligne. |
| `ImapLegacy` | ⚠️ Ancienne implémentation via `ext-imap`, **dépréciée**. Refuse de démarrer si l'extension manque. |
| `Gmail`, `Mbox`, `Pst` | ❌ Non implémentés. |

### Sortie (`out`)

| Valeur | Produit |
|---|---|
| `Eml` | ✅ Un `.eml` par message : l'original, octet pour octet. |
| `Html` | ✅ Archive navigable : une page par message, un index par dossier, pièces jointes liées. |
| `Pdf` | ✅ Un document par message, **pièces jointes embarquées** dans le PDF. Requiert `ext-gd`. |
| `Mbox` | ✅ Un fichier mbox par dossier, au format mboxrd, relisible par un client de messagerie. |
| `Test` | ✅ Essai à blanc : journalise ce qui serait écrit, sans rien écrire. |
| `Csv` | ❌ Non implémenté. |

Ajouter un format revient à déposer une classe dans `cli/src/Out/` : voir
[`MECANISME_IN_OUT.md`](MECANISME_IN_OUT.md).

---

## Filtrer par date

```ini
since  = "2023-01-01"   ; incluse
before = "2024-01-01"   ; exclue
```

L'intervalle ci-dessus couvre exactement l'année 2023. `before = "-2 years"`
est recalculé à chaque exécution, ce qui convient à une tâche planifiée.

Le filtre est **poussé au serveur** : les messages hors fenêtre ne sont jamais
téléchargés. Il porte sur la **date d'envoi** (en-tête `Date:`), la même que
celle qui nomme les fichiers.

---

## Supprimer après archivage

> ⚠️ `delete = 1` supprime des messages **sans confirmation**. Lisez la
> [procédure recommandée](examples/README.md#purger-la-boîte-après-archivage)
> avant de l'activer.

```ini
trash = 1       ; vers la corbeille — recommandé pour une première purge
```

Pour savoir quelle corbeille sera choisie, ou en nommer une autre, commencez
par **regarder les dossiers du compte** :

```bash
php cli.php folders ma-boite
```

```
+----------+-----------+------------------------+------------------------------------+---------------------+
| Messages |           | Nom (pour trash)       | Chemin IMAP                        | Attributs           |
+----------+-----------+------------------------+------------------------------------+---------------------+
| 4568     |           | INBOX                  |                                    | HasNoChildren       |
| 305      |           | INBOX/Éléments envoyés | INBOX.&AMk-l&AOk-ments envoy&AOk-s | HasNoChildren       |
| 12       | corbeille | INBOX/Corbeille        | INBOX.Corbeille                    | HasNoChildren Trash |
+----------+-----------+------------------------+------------------------------------+---------------------+
```

Les noms de dossiers voyagent encodés sur le réseau, donc impossibles à
deviner de l'extérieur : la commande montre le nom lisible **et** l'identifiant
brut du serveur. `trash` accepte l'un comme l'autre, et `/` remplace le
séparateur du serveur.

```ini
trash = "INBOX/Corbeille"
```

```ini
delete = 1      ; effacement définitif, sans filet
```

`trash` se suffit à lui-même : demander la corbeille, c'est déjà demander que
les messages quittent leurs dossiers.

Quatre conditions doivent être réunies pour qu'un message disparaisse :

1. `delete = 1` ou `trash` explicitement — les deux absents par défaut ;
2. la source sait supprimer ;
3. le format de sortie se porte garant de son archive — un essai à blanc, par
   exemple, ne l'autorise pas ;
4. **l'archive ZIP existe** — sinon la purge est abandonnée et journalisée.

Sur une **boîte pleine**, le déplacement échoue : il demande au serveur de
détenir le message deux fois. `trash_mode = "append"` inverse l'ordre — lire,
effacer, redéposer — et passe là où `MOVE` et `COPY` sont refusés. Lisez
[ses contreparties](examples/README.md#quand-la-boîte-est-pleine) avant de
l'activer.

La suppression est la **toute dernière étape** de l'export et ne porte que sur
les messages effectivement archivés. Avec `trash`, une corbeille introuvable
ou une copie qui échoue interrompt la purge **sans rien effacer**, plutôt que
de se rabattre sur une suppression définitive.

---

## Reprendre un export interrompu

Relancez la même commande. Les messages déjà écrits sont recensés dans
`saved_emails.json` et ne sont pas retéléchargés.

Élargir la fenêtre de dates et relancer n'importe que le complément.
Rétrécir la fenêtre **ne supprime rien** de ce qui est déjà archivé : une
archive ne fait que s'enrichir.

---

## Ce que produit l'export

```
~/.config/mailbxzip/
├── config/
│   └── ma-boite                      votre configuration
└── archives/
    ├── utilisateur@exemple.fr/
    │   ├── index.html                (sortie Html)
    │   ├── export.log                journal horodaté
    │   ├── saved_emails.json         index de reprise
    │   ├── INBOX/
    │   │   ├── 2024-01-08 - contact - Objet.eml
    │   │   └── .eml/                 sources, si wSource = 1
    │   └── INBOX/Éléments envoyés/
    └── ma-boite.zip                  le livrable
```

Les fichiers sont nommés `AAAA-MM-JJ - correspondant - objet`. Pour un message
que vous avez envoyé, le correspondant retenu est le **destinataire** — le nom
reste ainsi parlant dans le dossier « Envoyés ».

### Suivre un export en cours

```bash
tail -f ~/.config/mailbxzip/archives/utilisateur@exemple.fr/export.log
```

Le journal indique la progression, une estimation du temps restant, et
préfixe les incidents `[WARNING]` ou `[ERROR]`. C'est le premier endroit à
consulter si un message manque à l'appel.

---

## Commandes

```bash
php cli.php mailbox --start <config>    # lance ou reprend un export
php cli.php folders <config>            # liste les dossiers, repère la corbeille
php cli.php list                        # liste les commandes
php cli.php help config                 # format du fichier de configuration
```

> Les options `--stop`, `--pause` et `--daemon`, ainsi que la commande
> `config` et ses options, **figurent dans l'aide mais ne sont pas encore
> implémentées**. Voir les lots L3 et L4 de la [feuille de route](ROADMAP.md).

---

## Tests

```bash
cd cli
composer test            # harnais complet
composer run test-pdf    # avec ext-gd, couvre en plus la sortie PDF
```

Le harnais est en PHP nu, sans dépendance supplémentaire. Il couvre le pipeline
de bout en bout : dossiers accentués, règle de nommage, collisions, format
mbox, gabarits HTML et PDF, pièces jointes, reprise, filtre de dates,
suppression et corbeille. Les scénarios IMAP s'exécutent contre un serveur de
test minimal (`cli/tests/fake-imap-server.py`) que le harnais démarre lui-même,
et ignore proprement si `python3` est absent.

---

## Sécurité

Le mot de passe figure **en clair** dans le fichier de configuration, que
l'outil relit et réécrit à chaque message. C'est commode pour un essai, mais
inadapté à une exploitation durable. À défaut de mieux pour l'instant :

```bash
chmod 600 ~/.config/mailbxzip/config/ma-boite
```

L'archive produite contient une correspondance intégrale et **n'est pas
chiffrée** : traitez le `.zip` avec les mêmes égards que la boîte elle-même.

Sortir les identifiants du fichier de configuration est le lot `L0.3` de la
[feuille de route](ROADMAP.md).

---

## Documentation

| Document | Contenu |
|---|---|
| [`examples/README.md`](examples/README.md) | Les configurations d'exemple, expliquées une à une. |
| [`ANALYSE_FONCTIONNELLE.md`](ANALYSE_FONCTIONNELLE.md) | Ce que fait l'application, fonction par fonction. |
| [`MECANISME_IN_OUT.md`](MECANISME_IN_OUT.md) | Le pipeline `In`/`Out` en détail — à lire avant d'écrire un connecteur. |
| [`ROADMAP.md`](ROADMAP.md) | Ce qui est fait, ce qui reste, et pourquoi. |

---

## Licence

GPL-3.0-or-later — voir [`LICENSE`](LICENSE).
