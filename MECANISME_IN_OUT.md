# Le mécanisme `In` / `Out` — analyse détaillée

> Analyse du dépôt `main` (commit `e9549b2`). Complète
> `ANALYSE_FONCTIONNELLE.md`.

---

## 1. Principe

Mailbxzip repose sur un **pipeline à deux extrémités interchangeables** :

```
   ┌─────────────┐        ┌──────────────┐        ┌──────────────┐
   │ Connecteur  │  Eml   │   Mailbox    │  Eml   │  Connecteur  │
   │     IN      │ ─────► │(orchestrateur)│ ─────► │     OUT      │
   │  (source)   │        │              │        │   (format)   │
   └─────────────┘        └──────────────┘        └──────────────┘
    d'où viennent          quoi faire de           sous quelle forme
     les messages          chaque message            les écrire
```

L'idée directrice : **`Mailbox` ne sait rien ni de l'IMAP ni du PDF**. Il ne
connaît que deux collaborateurs anonymes et un objet pivot, la classe `Eml`.
Ajouter une source (Gmail, PST…) ou un format (HTML, CSV…) ne demande donc
aucune modification de l'orchestrateur.

C'est une variante du patron **Strategy**, avec une résolution des stratégies
**par nom de classe, à l'exécution, depuis le fichier de configuration**.

---

## 2. Résolution des connecteurs

Tout part de deux lignes du fichier INI :

```ini
in  = Imap
out = Pdf
```

`Mailbox::__construct()` en déduit deux noms de classes pleinement qualifiés et
les instancie directement (`cli/src/Mailbox.php:49-58`) :

```php
$inputNamespace  = "Mailbxzip\\Cli\\In\\"  . $this->config['in'];
$outputNamespace = "Mailbxzip\\Cli\\Out\\" . $this->config['out'];

$this->inputHandler  = new $inputNamespace($this->config, $this);
$this->outputHandler = new $outputNamespace($this->config, $this);
```

**Conséquences de ce choix :**

| Aspect | Conséquence |
|---|---|
| Extensibilité | Déposer une classe dans `src/In/` ou `src/Out/` suffit : l'autoloader PSR-4 la trouve, la configuration l'active. Aucun registre, aucune injection à déclarer. |
| Découverte | La commande `config` **scanne les répertoires** `src/In` et `src/Out` pour bâtir son aide (`cli.php:130-170`). Un connecteur se documente donc lui-même. |
| Robustesse | Aucune validation : un `in = Toto` produit une `Error` PHP brute (« Class not found »), pas un message d'erreur exploitable. |
| Sûreté | La valeur du fichier INI est concaténée dans un nom de classe et instanciée. La surface est limitée aux classes existantes des deux namespaces, mais toute classe qui y serait déposée devient instanciable via la configuration. |

---

## 3. Le contrat

Aucune **interface PHP n'est déclarée**. Le contrat est purement implicite,
déduit des appels effectués par `Mailbox`.

### 3.1 Connecteur d'entrée — `Mailbxzip\Cli\In\*`

| Membre | Signature attendue | Rôle |
|---|---|---|
| `__construct` | `($config, $mailbox)` | Connexion / initialisation. |
| `getFolders()` | → `['folders' => [nom => nb], 'total' => int]` | Arborescence et volumétrie. |
| `getEmails()` | → `[dossier => [uid, …]]` | Inventaire des messages à traiter. |
| `getEmail($uid, $folder)` | → `Eml` | Récupération d'un message. |
| `preFunc()` / `postFunc()` | — | Crochets optionnels. |

### 3.2 Connecteur de sortie — `Mailbxzip\Cli\Out\*`

| Membre | Signature attendue | Rôle |
|---|---|---|
| `__construct` | `($config, \Mailbxzip\Cli\Mailbox $mailbox = null)` | Initialisation. |
| `setFolders($folders)` | — | Matérialisation de l'arborescence cible. |
| `saveEmails($eml)` | — | Persistance d'**un** message (le pluriel est trompeur). |
| `preFunc()` / `postFunc()` | — | Crochets optionnels. |

### 3.3 Constantes déclaratives

| Constante | Lue par | Statut |
|---|---|---|
| `HELP` | `ConfigCommand::generateHelpFromClass()` | ✅ Active |
| `MINIMAL_CONFIG_VAR` | idem | ✅ Active |
| `CONFIG_VAR` | idem | ✅ Active |
| `PROHIBITED_CONFIG` | `Mailbox::isConfigEntryAllowed()` | ⚠️ Mécanisme lu, **mais déclaré par aucune classe** |
| `CAN_DELETE` | — | ❌ Déclarée par 4 classes, **lue nulle part** |

Le mécanisme `PROHIBITED_CONFIG` permet à un connecteur d'interdire une option
de configuration (lever une exception si l'utilisateur la demande). Il n'est
aujourd'hui sollicité que pour `wSource`, et aucune classe ne l'utilise : c'est
une extension prête mais inexploitée.

---

## 4. L'objet pivot `Eml`

`Eml` est le **seul type partagé** entre les deux extrémités, et donc le
véritable cœur du contrat. `Mailbox` ne le manipule presque pas : il le reçoit
de `In` et le passe à `Out`.

```
In::getEmail()  ──►  Eml  ──►  Out::saveEmails()
                      │
                      ├── getContent()    source .eml brute
                      ├── getFolder()     dossier de destination
                      ├── get()           tableau décodé (sujet, from, to, …)
                      ├── getAttachments()
                      ├── view($tpl)      rendu Twig
                      └── filename()      nom de fichier métier
```

Point de conception notable : **`Eml` porte lui-même sa logique de nommage et
de rendu**. Un connecteur de sortie n'a donc ni à parser le MIME, ni à inventer
un nom de fichier — ce qui explique la brièveté de `Out/Eml` et `Out/Mbox`.
Le décodage MIME est fait **une seule fois**, dans le constructeur.

En contrepartie, `Eml` mélange trois responsabilités (modèle, présentation via
`view()`, politique de nommage via `filename()`), et le décodage étant fait à
la construction, il est payé même quand la sortie n'en a pas besoin — `Out/Eml`
et `Out/Mbox` n'utilisent que `getContent()`.

---

## 5. Cycle de vie complet

Séquence effective de `Mailbox::start()` (`cli/src/Mailbox.php:88-110`) :

```
Mailbox::__construct()
  ├─ parse_ini_file()
  ├─ new In\X($config, $this)          ← connexion à la source
  ├─ new Out\Y($config, $this)
  └─ createDirectories()               ← archives/, tmp/, <archive>/, export.log

start()
  ├─ startTime()
  ├─ si 'state' absent du fichier INI :
  │    ├─ updateState('start')
  │    └─ preFunc()   → In::preFunc() puis Out::preFunc()
  │
  ├─ getFolders()
  │    ├─ In::getFolders()             → ['folders' => …, 'total' => …]
  │    └─ Out::setFolders(folders)     → mkdir de l'arborescence
  │
  ├─ process()
  │    ├─ In::getEmails()              → [dossier => [uid…]]
  │    ├─ chargement de saved_emails.json
  │    └─ pour chaque dossier, pour chaque uid :
  │         ├─ si déjà dans saved_emails.json → skip
  │         ├─ updateState('get email <uid>')
  │         ├─ eml = In::getEmail(uid, dossier)
  │         ├─ Out::saveEmails(eml)
  │         ├─ saved_emails.json ← uid          (écrit à chaque message)
  │         └─ Mailbox::saveSource(eml)         (si wSource = 1)
  │
  ├─ postFunc()  → In::postFunc() puis Out::postFunc()
  ├─ createZipArchive()
  ├─ endTime()
  └─ logExportDuration()
```

### 5.1 Deux subtilités du cycle

**`preFunc()` ne s'exécute qu'une fois dans la vie d'une configuration.** Il est
conditionné à l'absence de la clé `state`, or `updateState()` écrit cette clé
de façon définitive dans le fichier INI. Une reprise après interruption
**saute donc `preFunc()`**, alors que `postFunc()` est appelé à chaque
exécution. L'asymétrie n'est probablement pas intentionnelle : un connecteur
qui utiliserait `preFunc()` pour préparer une ressource nécessaire à
`saveEmails()` échouerait à la reprise.

**Le couplage inverse.** Chaque connecteur reçoit `$this` (le `Mailbox`) et
s'en sert : `Out/*::getConfig()` relit la configuration **vivante** de
l'orchestrateur plutôt que la copie figée reçue au constructeur — c'est ainsi
que `emailArchivePath`, calculé après coup, devient visible. Les connecteurs
appellent aussi `$this->mailbox->log()` et `saveSource()`. Le découplage est
donc unidirectionnel en apparence seulement.

---

## 6. Où passe l'information des dossiers

C'est le point le plus délicat du mécanisme, car **le nom de dossier suit deux
chemins distincts** :

```
                    ┌── getFolders() ──► noms NETTOYÉS ──► Out::setFolders() ──► mkdir
   Source IMAP ─────┤                    (UTF-7 décodé, UTF-8, '.'→'/')
                    └── getEmails()  ──► noms BRUTS ──┬──► saved_emails.json (clés)
                                        {serveur}A.B  │
                                                      └──► getEmail(uid, brut)
                                                             └─► Eml::getFolder()
                                                                 = sanitizeFolderName(brut)
                                                                   ('.'→'/', serveur retiré)
                                                                        │
                                                                        ▼
                                                              chemin d'écriture du fichier
```

`Out::setFolders()` crée les répertoires à partir des noms **nettoyés** de
`getFolders()`, tandis que le chemin d'écriture réel vient de
`Eml::getFolder()`, alimenté par `Imap::sanitizeFolderName()`.

**Or ces deux normalisations ne sont pas identiques**
(`cli/src/In/Imap.php:62-78` contre `:155-161`) :

| Traitement | `getFolders()` | `sanitizeFolderName()` |
|---|---|---|
| Retrait du préfixe serveur | ✅ | ✅ |
| `.` → `/` | ✅ | ✅ |
| Décodage UTF-7 → UTF-8 | ✅ | ❌ |
| Suppression des `\0` | ✅ | ❌ |

Pour un dossier ASCII (`INBOX`, `Sent`) les deux coïncident et tout fonctionne.
Pour un dossier **accentué** — cas courant en français : `Éléments envoyés`,
`Brouillons`, `Indésirables` — IMAP renvoie du UTF-7 modifié
(`&AMk-l&AOk-ments`). Le répertoire est alors créé sous son nom **décodé**,
mais les messages sont écrits vers le nom **encodé** : le répertoire cible
n'existe pas.

Conséquence : `file_put_contents()` échoue. Et comme il n'émet qu'un
*warning* sans lever d'exception, le bloc `try/catch` de `Out/Eml` et
`Out/Mbox` ne se déclenche pas — **les messages sont perdus silencieusement**,
tout en étant marqués comme traités dans `saved_emails.json`.

---

## 7. La chaîne de repli, et pourquoi elle ne se déclenche jamais

Les trois connecteurs de sortie réels appliquent le même schéma défensif :

```php
try {
    // écriture
} catch (Exception $e) {
    $this->mailbox->saveSource($eml, true);   // repli : conserver le .eml
    $this->mailbox->log('Unable to save…', 'ERROR');
}
```

L'intention est bonne — ne jamais perdre un message — mais **le repli est
inatteignable dans les trois cas** :

| Connecteur | Cause |
|---|---|
| `Out/Eml`, `Out/Mbox` | `file_put_contents()` retourne `false` et émet un *warning* ; il **ne lève pas d'exception**. Le `catch` est du code mort. |
| `Out/Pdf` | `html2pdf()` **capture déjà lui-même** ses exceptions et retourne `false` ; `saveEmails()` ignore cette valeur de retour. Le `catch` extérieur ne voit jamais rien passer. |

Et même si le `catch` se déclenchait, `Mailbox::saveSource()` est déclarée
**`private`** (`Mailbox.php:558`) : l'appel depuis un connecteur produirait une
erreur fatale — précisément au moment où le filet devait jouer.

S'y ajoute, pour `Out/Pdf`, un enchaînement non gardé : `attachFilesToPdf()`
est appelé **inconditionnellement** après `html2pdf()`. Si le PDF n'a pas été
produit, `SetSourceFile()` s'exécute sur un fichier absent et lève une
exception non rattrapée, qui interrompt tout l'export.

---

## 8. État réel des implémentations

| Connecteur | Contrat | Remarque |
|---|---|---|
| `In/Imap` | ✅ conforme | **Connecteur IMAP par défaut**, sans aucune extension PHP, sur `webklex/php-imap` (l'un des deux paquets nommés par le RFC PHP). Comprend les anciennes chaînes `{host:port/imap/ssl}`. |
| `In/ImapLegacy` | ✅ conforme | Ancienne implémentation via `ext-imap`, **dépréciée** — l'extension est sortie du cœur de PHP en 8.4. Refuse de démarrer si elle manque. |
| `In/Test` | ❌ non conforme | `getEmails()` retourne une liste plate `[0,1,2,3,4]` au lieu de `[dossier => [uid]]` ; `getEmail($id)` ignore `$folder` et retourne un **tableau** au lieu d'un `Eml`. Inutilisable. |
| `In/Gmail`, `In/Pst`, `In/Mbox` | — | Fichiers **vides**. |
| `Out/Pdf` | ✅ conforme | Le plus abouti : Twig → mPDF, pièces jointes réinjectées en annotations. |
| `Out/Eml` | ✅ conforme | |
| `Out/Mbox` | ⚠️ | Conforme au contrat, mais produit un MBOX non standard (pas de ligne `From ` séparatrice). |
| `Out/Test` | ❌ non conforme | Le type-hint `Mailbox $mailbox = null` n'a **pas de `use`** ni de `\` initial : il se résout en `Mailbxzip\Cli\Out\Mailbox`, classe inexistante → `TypeError` dès qu'un vrai `Mailbox` est passé. Les trois autres sorties utilisent `\Mailbxzip\Cli\Mailbox` pleinement qualifié. |
| `Out/Html`, `Out/Csv` | — | Fichiers **vides**. |

> Conséquence directe : `cli/config.example.ini` livre `in = Test` / `out = Test`
> comme configuration par défaut — **les deux connecteurs de démonstration sont
> cassés**. Le premier contact avec l'outil échoue.

---

## 9. Contraintes d'exécution héritées du mécanisme

- **`Out/Pdf` impose le répertoire de travail.** `View::R()` instancie
  `new FilesystemLoader('views')` avec un chemin **relatif** : la commande doit
  être lancée depuis `cli/`, sinon Twig ne trouve pas les gabarits. Les autres
  sorties n'ont pas cette contrainte.
- **`setFolders()` ne crée que les dossiers annoncés par `getFolders()`.** Un
  dossier apparaissant seulement dans `getEmails()` n'aura pas de répertoire.
- **Le `total` de `getFolders()` est ignoré.** `process()` recompte à partir de
  `getEmails()`. Un connecteur peut donc retourner un `total` faux sans effet.
- **`saveEmails()` traite un seul message.** Aucun mécanisme de traitement par
  lot ni de `flush` final n'est prévu : un connecteur qui voudrait bufferiser
  devrait le faire dans `postFunc()`.

---

## 10. Appréciation d'ensemble

**Ce que le mécanisme réussit.** La séparation source / format est juste et bien
posée : les deux axes de variation du produit (d'où viennent les messages, sous
quelle forme les écrire) sont exactement les deux points d'extension. L'objet
`Eml` en pivot évite la duplication du parsing MIME. L'aide auto-documentée par
constantes est élégante : un connecteur déclare son propre paramétrage. Et
l'orchestrateur concentre à juste titre ce qui ne varie pas — reprise,
progression, journal, ZIP.

**Ce qui le fragilise.** Le contrat n'est nulle part écrit. Il n'existe ni
interface, ni classe abstraite, ni test : rien ne vérifie qu'un connecteur le
respecte. Le résultat est visible — sur 6 connecteurs implémentés, **2 violent
le contrat**, et ce sont précisément ceux de la configuration par défaut. Les
divergences ne se manifestent qu'à l'exécution, souvent tardivement.

S'y ajoute un défaut de conception plus profond : **les chemins d'erreur ne
sont jamais empruntés**. Repli inatteignable, méthode `private` appelée de
l'extérieur, `catch` sur une classe inexistante, valeur de retour ignorée — ces
quatre défauts convergent au même endroit et n'ont manifestement jamais été
exercés. Le mécanisme est fiable tant que tout se passe bien.

**Priorités.** Dans l'ordre :

1. Déclarer `InputHandlerInterface` et `OutputHandlerInterface` — rend
   immédiatement visibles les non-conformités de `In/Test` et `Out/Test`
   (lot **L5.0** de la roadmap).
2. Réparer la chaîne de repli et unifier la normalisation des noms de dossiers
   (**L1.1**, **L1.6**, **L2.x**) — c'est là que se perdent des messages.
3. Valider `in` / `out` à la résolution, avec un message d'erreur explicite.
4. Rendre `preFunc()` symétrique de `postFunc()`.
