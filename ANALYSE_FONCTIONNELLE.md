# Analyse fonctionnelle — Mailbxzip CLI

> Document d'analyse fonctionnelle établi par rétro-ingénierie du code source
> (branche `main`, commit `e9549b2`). Version applicative déclarée : `0.1`.

---

## 1. Présentation générale

### 1.1 Objet du produit

**Mailbxzip CLI** est un outil en ligne de commande (PHP) dont la finalité est
d'**archiver le contenu complet d'une boîte aux lettres électronique dans une
archive ZIP**, en conservant l'arborescence des dossiers de la messagerie.

Le slogan du dépôt résume la promesse : *« get your mailbox in a zip file »*.

### 1.2 Cas d'usage métier

| Cas d'usage | Description |
|---|---|
| Archivage légal / conformité | Conserver une copie hors-ligne et pérenne d'une boîte mail (départ d'un collaborateur, fin de contrat, obligation de conservation). |
| Migration / résiliation | Récupérer les e-mails avant fermeture d'un compte ou changement d'hébergeur. |
| Portabilité des données (RGPD) | Fournir à une personne l'export de ses e-mails dans un format lisible. |
| Consultation hors messagerie | Obtenir les e-mails en PDF, consultables sans client de messagerie. |

### 1.3 Positionnement technique

- Application **PHP en ligne de commande**, bâtie sur **Symfony Console**.
- Architecture **pipeline entrée → traitement → sortie** (`In` / `Out`)
  entièrement pilotée par un **fichier de configuration INI par boîte mail**.
- Traitement **long, reprenable** (idempotent e-mail par e-mail) avec
  journalisation et suivi de progression.

---

## 2. Acteurs et périmètre

### 2.1 Acteurs

| Acteur | Rôle |
|---|---|
| **Opérateur / administrateur** | Rédige le fichier de configuration d'une boîte, lance et supervise l'export depuis un terminal. |
| **Système appelant** (potentiel) | Le mode `--daemon` et l'écriture de l'état dans le fichier INI suggèrent un pilotage par un ordonnanceur ou une interface tierce. |
| **Serveur de messagerie** | Source des données (IMAP/POP3/NNTP). |

### 2.2 Périmètre couvert

**Dans le périmètre :** connexion à une boîte distante, énumération des
dossiers, téléchargement des messages, conversion vers un format cible,
reconstitution de l'arborescence sur disque, compression ZIP, journalisation.

**Hors périmètre (à ce stade) :** interface graphique, gestion multi-comptes en
parallèle, chiffrement de l'archive, notification de fin de traitement.

*(La purge de la boîte source après archivage, initialement hors périmètre, est
désormais couverte — voir la clé `delete`.)*

---

## 3. Architecture fonctionnelle

### 3.1 Vue d'ensemble du flux

```
  Fichier de configuration INI (1 par boîte)
                 │
                 ▼
        ┌──────────────────┐
        │  Commande CLI    │  php cli.php mailbox --start <fichier>
        │  (cli.php)       │
        └────────┬─────────┘
                 ▼
        ┌──────────────────────────────────────────┐
        │           Orchestrateur Mailbox          │
        │  état · progression · logs · reprise     │
        └───┬──────────────────────────────┬───────┘
            │                              │
   ┌────────▼────────┐            ┌────────▼─────────┐
   │  Connecteur IN  │            │  Connecteur OUT  │
   │  Imap · Test    │            │  Pdf·Eml·Mbox·…  │
   │  (Gmail·Pst·    │            │  (Csv·Html : à   │
   │   Mbox : à faire)│           │   faire)         │
   └────────┬────────┘            └────────┬─────────┘
            │      objet Eml (message)     │
            └──────────────►───────────────┘
                                           │
                                           ▼
                             Arborescence de fichiers
                                           │
                                           ▼
                                 Archive ZIP finale
```

### 3.2 Composants fonctionnels

| Composant | Fichier | Responsabilité fonctionnelle |
|---|---|---|
| Point d'entrée CLI | `cli/cli.php` | Déclare l'application, les commandes `mailbox` et `config`, les options globales. |
| Orchestrateur | `cli/src/Mailbox.php` | Chef d'orchestre : charge la configuration, instancie les connecteurs, pilote la boucle d'export, gère état / progression / reprise / ZIP. |
| Modèle message | `cli/src/Eml.php` | Représente un e-mail : décodage MIME, extraction en-têtes / corps / pièces jointes, calcul du nom de fichier. |
| Journalisation | `cli/src/Log.php` | Écrit les événements horodatés dans `export.log` et sur la console. |
| Rendu | `cli/src/View.php` | Rendu des gabarits Twig (utilisé pour la mise en page PDF). |
| Connecteurs d'entrée | `cli/src/In/*.php` | Abstraction d'une source de messages. |
| Connecteurs de sortie | `cli/src/Out/*.php` | Abstraction d'un format d'archivage. |
| Gabarits | `cli/views/pdf/*` | Présentation d'un e-mail en HTML avant conversion PDF. |

---

## 4. Fonctions détaillées

### F1 — Configuration d'une boîte à archiver

Chaque boîte à traiter est décrite par un **fichier INI** placé dans
`~/.config/mailbxzip/config/<nom>` (le nom du fichier est l'argument passé à la
commande ; par convention l'adresse e-mail).

Paramètres fonctionnels identifiés :

| Clé | Rôle | Obligatoire |
|---|---|---|
| `address` | Adresse de la boîte ; sert aussi de **nom du dossier d'archive** et à distinguer les messages envoyés des messages reçus lors du nommage des fichiers. | Oui |
| `in` | Connecteur d'entrée à utiliser (`Imap`, `Test`, …). | Oui |
| `out` | Connecteur de sortie à utiliser (`Pdf`, `Eml`, `Mbox`, `Test`, …). | Oui |
| `server` | Chaîne de connexion IMAP PHP, ex. `{ssl0.ovh.net:993/imap/ssl}`. | Oui (entrée Imap) |
| `username` / `password` | Identifiants de connexion. | Oui (entrée Imap) |
| `wSource` | `1` = conserver en plus le `.eml` source de chaque message dans un sous-dossier `.eml`. | Non |
| `debugHtml` | `1` = conserver le HTML intermédiaire à côté du PDF (mise au point). | Non |
| `archives_dir` / `tmp_dir` | Surcharge des répertoires de travail. | Non |
| `since` / `before` | Restreint l'export aux messages **envoyés** dans cette fenêtre (`AAAA-MM-JJ`) ; `since` incluse, `before` exclue. Poussée au serveur en IMAP. | Non |
| `delete` | `1` = supprimer de la source les messages archivés, **une fois le ZIP écrit**. Destructif, refusé si les connecteurs ne l'autorisent pas. | Non |
| `trash` | Avec `delete` : `1` déplace les messages vers la corbeille du serveur (détectée par `\Trash`) au lieu de les effacer, ou nommez le dossier. | Non |

Clés **gérées par l'application** (écrites automatiquement dans le même
fichier) : `status`, `state`, `progress`, `start_time`, `end_time`,
`emailArchivePath`.

> Le fichier de configuration joue donc un **double rôle** : paramétrage saisi
> par l'opérateur **et** support de persistance de l'état d'avancement.

### F2 — Aide contextuelle sur la configuration

La commande `config` construit dynamiquement une aide en parcourant les
répertoires `src/In` et `src/Out` et en lisant, sur chaque classe rencontrée,
les constantes `HELP`, `MINIMAL_CONFIG_VAR` et `CONFIG_VAR`. **Ajouter un
connecteur enrichit automatiquement l'aide**, sans modification du code de la
commande.

Options annoncées : `--addConfig`, `--listConfig`, `--stateConfig`, `--option`.

### F3 — Lancement et pilotage d'un export

Commande : `php cli.php mailbox --start <fichier-de-config>`

| Option | Fonction attendue |
|---|---|
| `--start` | Démarre l'export. |
| `--stop` | Arrête l'export. |
| `--pause` | Suspend l'export. |
| `-c, --config` | Désigne un dossier de configuration alternatif. |
| `-d, --daemon` | Exécution en arrière-plan. |
| `-v`, `-q`, `--silent` | Niveaux de verbosité (Symfony Console). |

### F4 — Découverte de l'arborescence de la boîte

Le connecteur d'entrée retourne la **liste des dossiers avec le nombre de
messages** de chacun, ainsi qu'un total. Les noms de dossiers sont normalisés :
décodage UTF-7 IMAP → conversion UTF-8, suppression du préfixe serveur,
transformation des séparateurs de sous-dossiers (`.`) en `/`.

Cette arborescence est immédiatement **répliquée sur le système de fichiers**
par le connecteur de sortie, y compris pour les dossiers vides.

### F5 — Récupération et traitement des messages

Pour chaque dossier, le connecteur d'entrée fournit la liste des identifiants
(UID) de messages ; chaque message est ensuite téléchargé (en-tête + corps,
soit le format `.eml` brut) et encapsulé dans un objet `Eml`.

L'objet `Eml` réalise le **décodage MIME** (bibliothèque
`zbateson/mail-mime-parser`) et expose : sujet, expéditeur, destinataires (to,
cc, bcc), date, corps texte, corps HTML (réparé via DOM) et la liste des
**pièces jointes** (nom assaini, type MIME, taille brute et lisible, contenu).

### F6 — Nommage des fichiers produits

Règle métier de nommage : `AAAA-MM-JJ - <correspondant> - <objet>`

- Le **correspondant** est l'expéditeur ; si l'expéditeur correspond à
  `address` (message envoyé), c'est le **destinataire** qui est retenu — le nom
  reste ainsi porteur de sens dans le dossier « Envoyés ».
- Seule la partie locale de l'adresse (avant `@`) est conservée.
- Assainissement : caractères non alphanumériques remplacés par `-`, tirets
  consécutifs fusionnés, **troncature à 50 caractères**.
- En cas d'échec (date illisible, etc.) : repli sur `email-<uid>`.

### F7 — Export vers un format cible

| Format | Connecteur | Comportement |
|---|---|---|
| **PDF** | `Out/Pdf` | Rend le message via le gabarit Twig `pdf/mail.html` (en-têtes, liste des pièces jointes, corps HTML), convertit en UTF-8 puis en PDF via **mPDF**. Les très gros documents (> 1 Mo de HTML) sont **découpés en fragments** selon le séparateur balise le plus fréquent avant écriture. Les **pièces jointes sont ensuite réinjectées dans le PDF** sous forme d'annotations de type fichier (PDF autoportant). |
| **EML** | `Out/Eml` | Écrit le message source tel quel, un fichier `.eml` par message. |
| **MBOX** | `Out/Mbox` | Concatène les messages d'un dossier dans un unique fichier `email.mbox`. |
| **Test** | `Out/Test` | Sortie de mise au point (affichage console). |

En cas d'échec de génération, le message source est **systématiquement
sauvegardé en `.eml`** (mode de repli) et l'erreur est journalisée : aucun
message n'est perdu silencieusement.

### F8 — Conservation optionnelle des sources

Si `wSource = 1`, chaque message est **également** enregistré en `.eml` dans un
sous-dossier `.eml` du dossier courant, en complément du format cible. Un
mécanisme de garde (`PROHIBITED_CONFIG`) permet à un connecteur de déclarer
qu'une option de configuration lui est interdite ; la demande lève alors une
erreur explicite.

### F9 — Reprise sur interruption (idempotence)

Un fichier **`saved_emails.json`** est maintenu à la racine du dossier
d'archive : il recense, dossier par dossier, les UID déjà traités. Il est
réécrit **après chaque message**.

Au relancement, tout message déjà présent dans ce fichier est **ignoré** avec
la mention « already saved … skipping ». Un export interrompu (coupure réseau,
arrêt machine, quota) peut donc être relancé sans retraiter l'existant.

### F10 — Suivi d'avancement et journalisation

- **État courant** : la clé `state` du fichier INI est mise à jour en continu
  (`start`, `get email <uid>`, message de log courant). Elle est lisible par un
  processus tiers pendant l'exécution.
- **Progression** : la clé `progress` contient le pourcentage traité (1
  décimale).
- **Estimation du temps restant** : calculée à partir du temps écoulé et du
  débit moyen par message, affichée à chaque e-mail au format `HH:MM:SS`.
- **Durée totale** : `start_time` / `end_time` horodatent l'export ; la durée
  formatée est journalisée en fin de traitement.
- **Journal** : `export.log` dans le dossier d'archive, lignes horodatées et
  typées (`INFO`, `ERROR`), doublées sur la console en contexte CLI.

### F11 — Production de l'archive ZIP

En fin de traitement, l'intégralité du dossier d'archive est compressée dans
`<archives_dir>/<nom-du-fichier-de-config>.zip`. L'archivage est **récursif et
préserve les dossiers vides** (fidélité à l'arborescence d'origine). Une option
interne permet de supprimer la source après compression (non activée par
défaut).

---

## 5. Organisation des données produites

```
~/.config/mailbxzip/
├── config/
│   └── <adresse@domaine>            # configuration + état de l'export
├── tmp/
└── archives/
    ├── <adresse@domaine>/           # dossier de travail de l'archive
    │   ├── export.log               # journal d'exécution
    │   ├── saved_emails.json        # index de reprise (UID traités)
    │   ├── INBOX/
    │   │   ├── 2024-01-08 - contact - Objet du message.pdf
    │   │   └── .eml/                # sources, si wSource = 1
    │   ├── INBOX/Sous-dossier/
    │   └── Sent/
    └── <adresse@domaine>.zip        # livrable final
```

---

## 6. Extensibilité

L'architecture repose sur un **contrat implicite** (aucune interface PHP
formelle n'est déclarée) que doit respecter tout nouveau connecteur :

**Connecteur d'entrée (`Mailbxzip\Cli\In\*`)**

| Méthode | Attendu |
|---|---|
| `__construct($config, $mailbox)` | Initialisation / connexion. |
| `getFolders()` | `['folders' => [nom => nombre], 'total' => int]` |
| `getEmails()` | `[dossier => [uid, …]]` |
| `getEmail($uid, $folder)` | Un objet `Eml`. |
| `preFunc()` / `postFunc()` | Crochets début / fin (optionnels). |

**Connecteur de sortie (`Mailbxzip\Cli\Out\*`)**

| Méthode | Attendu |
|---|---|
| `__construct($config, $mailbox)` | Initialisation. |
| `setFolders($folders)` | Création de l'arborescence cible. |
| `saveEmails($eml)` | Persistance d'un message. |
| `preFunc()` / `postFunc()` | Crochets début / fin (optionnels). |

Constantes de documentation : `HELP`, `MINIMAL_CONFIG_VAR`, `CONFIG_VAR`,
`CAN_DELETE`, `PROHIBITED_CONFIG`.

Le choix du connecteur se fait **par nom de classe dans le fichier INI** :
ajouter un format revient à déposer une classe dans `src/In` ou `src/Out`.

---

## 7. État d'avancement fonctionnel

| Fonction | Statut |
|---|---|
| Entrée IMAP (`In/Imap`) | ✅ Implémentée — **connecteur par défaut**, en PHP pur, sans extension ; comprend les anciennes chaînes de connexion |
| Entrée IMAP historique (`In/ImapLegacy`) | ⚠️ Conservée mais **dépréciée** : repose sur `ext-imap`, sortie du cœur de PHP en 8.4 |
| Entrée Test (jeu de démonstration) | ⚠️ Implémentée, signature `getEmail()` divergente et retour non conforme au contrat (tableau au lieu d'un objet `Eml`) |
| Entrée Gmail (API) | ❌ Fichier vide — à réaliser |
| Entrée PST (Outlook) | ❌ Fichier vide — à réaliser |
| Entrée MBOX | ❌ Fichier vide — à réaliser |
| Sortie PDF (+ pièces jointes) | ✅ Implémentée |
| Sortie EML | ✅ Implémentée |
| Sortie MBOX | ✅ Implémentée |
| Sortie HTML | ✅ Implémentée — archive navigable, index par dossier, pièces jointes liées |
| Sortie CSV | ❌ Fichier vide — à réaliser |
| Compression ZIP finale | ✅ Implémentée |
| Reprise après interruption | ✅ Implémentée |
| Suivi progression / ETA / journal | ✅ Implémenté |
| Commande `mailbox --stop` / `--pause` | ❌ Déclarées, sans implémentation |
| Commande `config` (toutes options) | ❌ Déclarées, sans implémentation (seule l'aide générée fonctionne) |
| Mode `--daemon` | ❌ Option déclarée, sans effet |
| Suppression de la source après ZIP | ⚠️ Codée mais jamais activée |

---

## 8. Points d'attention fonctionnels

### 8.1 Sécurité et confidentialité

1. **Mots de passe en clair** dans les fichiers INI de configuration. Le dépôt
   contient un fichier `cli/config/g.robin@thulium-engineering.com` de ce type.
   `.gitignore` liste bien `cli/config`, mais **sans effet ici** : le fichier
   était déjà suivi par Git au commit `40270ec`, et il est donc présent dans
   `origin/main`. À traiter comme du ménage si le compte a été fermé après
   archivage — ce qui est l'usage même de l'outil — comme une fuite sinon.
2. **Aucun chiffrement de l'archive** produite, qui contient l'intégralité
   d'une correspondance.
3. **Permissions larges** : plusieurs répertoires sont créés en `0777`.

### 8.2 Robustesse

4. **Réécriture intégrale du fichier INI à chaque message** (état + progression)
   : coûteux en E/S et exposé à une corruption en cas d'interruption pendant
   l'écriture. Les commentaires du fichier d'origine sont par ailleurs perdus.
5. **Pas de reprise de connexion IMAP** : une coupure réseau interrompt
   l'export (rattrapée toutefois par le mécanisme de reprise, F9).
6. **Découpage HTML par balise** (`chunkHtml`) : le découpage retire le
   séparateur choisi, ce qui peut altérer la mise en page des très gros
   messages.
7. **Collision de noms de fichiers** : la troncature à 50 caractères et
   l'assainissement peuvent produire deux fichiers identiques dans un même
   dossier ; le second écrase le premier (sauf en sortie MBOX). Aucun
   suffixe de désambiguïsation n'est appliqué.
8. **Sortie MBOX** : les messages sont concaténés sans ligne d'en-tête
   `From ` (« From line ») séparatrice, ce qui n'est pas conforme au format
   MBOX standard et peut empêcher la relecture par un client de messagerie.
9. **Reprise et MBOX** : en cas de relance, l'index de reprise évite les
   doublons, mais un fichier `email.mbox` déjà partiellement écrit est complété
   par ajout — cohérent, à condition que l'index n'ait pas été supprimé.
10. **Visibilité de `saveSource()`** : la méthode est déclarée `private` dans
    `Mailbox` mais appelée depuis les connecteurs de sortie en mode repli, ce
    qui provoquerait une erreur fatale au moment précis d'un échec de
    conversion.

### 8.3 Cohérence

11. **Contrat des connecteurs non formalisé** : l'absence d'interface PHP laisse
    passer des implémentations divergentes (cas de `In/Test`).
12. **Constructeurs d'entrée** : `Mailbox` transmet deux arguments, alors que
    les connecteurs d'entrée n'en déclarent qu'un.
13. **`getArchivesPath()` / `getTmpPath()`** lisent les clés `archives` / `tmp`,
    alors que la configuration stocke `archives_dir` / `tmp_dir`.
14. **`var_dump()` résiduels** dans la génération de l'aide de la commande
    `config`, qui polluent la sortie.
15. **Dépendances PDF redondantes** : `dompdf`, `tcpdf`, `fpdi`, `fpdf`,
    `setasign/fpdf` et `mpdf` sont toutes déclarées alors que seul **mPDF** est
    réellement utilisé.

---

## 9. Évolutions fonctionnelles suggérées

| Priorité | Évolution |
|---|---|
| Haute | Implémenter réellement `--stop` / `--pause` (verrou et signal ; `symfony/lock` est déjà une dépendance). |
| Haute | Sortir les identifiants du fichier INI (variables d'environnement, trousseau, ou chiffrement au repos). |
| Haute | Dissocier le fichier de configuration (lecture seule) du fichier d'état (écriture fréquente). |
| Moyenne | Formaliser des interfaces PHP `InputHandlerInterface` / `OutputHandlerInterface`. |
| Moyenne | Rendre la sortie MBOX conforme (ligne `From ` et échappement). |
| Moyenne | Gérer les collisions de noms de fichiers (suffixe incrémental ou UID). |
| Moyenne | Compléter les connecteurs Gmail, PST, MBOX (entrée) et HTML, CSV (sortie). |
| Basse | Filtres d'export : ~~plage de dates~~ ✅ livré (`since` / `before`) ; restent les dossiers sélectionnés et la taille maximale. |
| Basse | Chiffrement optionnel de l'archive ZIP. |
| Basse | Élaguer les dépendances PDF inutilisées. |
| Basse | Ajouter une suite de tests automatisés (aucune n'existe à ce jour). |

---

## Annexe A — Version de travail `mailbxzip.zip` (prototype antérieur)

Une archive de travail (`mailbxzip.zip`, 1,2 Go) a été analysée. Son contenu
code a été extrait dans `_legacy-zip/` (les répertoires `archives/`,
`vendor/`, `vendor_old/`, `docs/` et les `.phar` ont été écartés : 837 Mo
d'archives de boîtes réelles et 393 Mo de dépendances).

### A.1 Conclusion : antérieure au dépôt

| Critère | Dépôt (`main`) | Archive ZIP |
|---|---|---|
| Date des sources | avril 2025 | février 2025 |
| Espace de noms | `Mailbxzip\Cli\` | `Ycdev\Mailbxzip\` |
| Architecture | pipeline `In` / `Out` enfichable | monolithe `Imap` + `Pdf` |
| Framework CLI | Symfony Console | `switch` sur `$argv` |
| Moteur PDF | mPDF | dompdf |
| Modèle de message | classe `Eml` dédiée | aucun |
| Formats de sortie | PDF, EML, MBOX (+ Test) | PDF uniquement |
| Reprise | `saved_emails.json` par dossier | fichier plat, sans distinction de dossier |
| Multi-comptes | un fichier de config par boîte | `getFirstConfig()` — une seule à la fois |

**Aucune modification n'a été intégrée** : le dépôt est en avance sur
l'ensemble des axes du périmètre CLI. Le prototype comporte par ailleurs des
régressions bloquantes (`index.php`, `api.php` et `cron.php` requièrent
`src/ImapArchiver.php`, absent de l'archive) et du code inachevé
(`createZipArchive()` s'interrompt sur le commentaire *« … reste du code
existant »* dès que `MAX_ZIP_SIZE_MB` est non nul).

### A.2 Fonctions présentes dans le prototype et absentes du dépôt

Ces éléments ne sont pas des régressions du dépôt mais des **pistes
fonctionnelles** déjà explorées, à reprendre le cas échéant :

| Fonction | Support dans le prototype | Intérêt |
|---|---|---|
| **API HTTP** | `api.php` : authentification puis commandes `add`, `status`, `getzip`, `downloadzip`, `uploadtodrive`. | Pilotage de l'archivage depuis une interface web ou un système tiers. |
| **Exécution planifiée** | `cron.php` : sélectionne la configuration en attente, archive, puis notifie. | Traitement par lots non supervisé. |
| **Livraison de l'archive** | Dépôt du ZIP sur une instance **Jirafeau** (lien à usage unique, expiration 1 mois) puis **envoi d'un e-mail** contenant le lien de téléchargement. | Boucle complète « archivage → mise à disposition → notification du demandeur ». |
| **Cycle de vie de la demande** | Statuts explicites `pending` → `import` → `zip` → `ready` / `error`, écrits dans la configuration. | Suivi d'état plus lisible que la clé `state` en texte libre du dépôt. |
| **Découpage du ZIP** | Constante `MAX_ZIP_SIZE_MB` et `getZipPaths()` retournant plusieurs fichiers. | Contournement des limites de taille en téléchargement ou en pièce jointe. *(Amorcé seulement : le code de découpage n'a jamais été écrit.)* |
| **Archivage de la configuration** | Le fichier de config est renommé en `.ini_bak` une fois l'export terminé. | Évite qu'une demande soit rejouée par le cron. |
| **Distribution en PHAR** | `cli/build.php` construit `mailbxzip-cli.phar`. | Livraison d'un exécutable autonome. |

> Le statut `ready` et la clé `status` présents dans `cli/config.example.ini`
> du dépôt sont un vestige de ce cycle de vie : ils ne sont plus lus par le
> code actuel.

### A.3 Alerte de sécurité

Le prototype contient des **secrets en clair** :

- `_legacy-zip/cli/cli.php` : mot de passe IMAP codé en dur, partagé pour
  toutes les boîtes ajoutées (`private static $password`).
- `_legacy-zip/cron.php` : clé d'API Jirafeau en dur.
- `_legacy-zip/config/*.ini` : quatre configurations de boîtes réelles
  (domaines `thulium-engineering.com` et `pcm-ensemblier.com`) avec mots de
  passe en clair.
- `index.php` : identifiants IMAP de production en dur dans du code mort.

`_legacy-zip/` et `mailbxzip.zip` ont été ajoutés au `.gitignore` afin
d'écarter tout risque de publication. **L'ensemble de ces identifiants est à
considérer comme compromis et doit être renouvelé.**

> L'archive extraite contenait également 837 Mo d'e-mails archivés de comptes
> réels (`archives/`), volontairement non copiés dans le dépôt.
