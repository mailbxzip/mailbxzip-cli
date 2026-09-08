# Roadmap d'intégration — Mailbxzip CLI

> Feuille de route établie à partir de `ANALYSE_FONCTIONNELLE.md` et
> `MECANISME_IN_OUT.md`, révisée après les livraisons **L1**,
> **L2.3 / L2.4 / L7.4 / L7.5**, puis **L8 (sortie de `ext-imap`)**.
>
> Les charges sont des ordres de grandeur en jours-homme, à consolider.

---

## Synthèse

| Lot | Intitulé | Charge restante | Priorité | État |
|---|---|---|---|---|
| **L0** | Mise en sécurité des secrets | 1,5 j | 🔴 Immédiate | À faire |
| **L1** | Correction des anomalies bloquantes | 0,25 j | 🔴 Haute | ✅ **Livré** (1 reliquat) |
| **L2** | Fiabilisation du traitement | 3,5 j | 🟠 Haute | ✅ Pertes fermées |
| **L3** | Pilotage réel de l'export | 4 j | 🟠 Moyenne | À faire |
| **L4** | Commande `config` opérationnelle | 3 j | 🟡 Moyenne | À faire |
| **L5** | Connecteurs manquants | 7,75 j | 🟡 Moyenne | Socle livré |
| **L6** | Reprise des acquis du prototype | 12 j | 🔵 Selon besoin produit | À arbitrer |
| **L7** | Qualité, tests et documentation | 3,25 j | 🟢 Continue | Amorcé |
| **L8** | Sortie de `ext-imap` (déprécié en 8.4) | 1 j | 🔴 Haute | ✅ **Livré** (reliquat) |
| **L9** | Filtre de date à l'entrée | 0,25 j | 🟡 Moyenne | ✅ **Livré** (reliquat) |
| **L10** | Sortie HTML et purge de la source | — | 🟡 Moyenne | ✅ **Livré** |
|  | **Total restant** | **~36 j** | | |

### Enchaînement recommandé

```
L1 ✅ ─┬─ L0 ─────────────────────────────────┐
       ├─ L2 ─┬─ L3 ─── L6                    │
       │      └─ L4                           ├─ L7 (transverse)
       └─ L5 ─────────────────────────────────┘
```

**Plus aucun chemin de perte de message n'est ouvert.** Le front principal
devient L2.1 (dissocier configuration et état), qui débloque L0.3 et L3.4. L5
est indépendant et parallélisable.

---

## L1 — Correction des anomalies bloquantes ✅ **Livré**

Le moteur ne perd plus de messages silencieusement et fonctionne dès le clone.
Couverture : `cli/tests/smoke.php`, **35 vérifications, 0 échec**.

### Livré

| # | Tâche | Résultat |
|---|---|---|
| L1.1 | `Mailbox::saveSource()` rendue publique | Le repli est appelable depuis les connecteurs. |
| L1.2 | `In/Test` remis sur le contrat | Retourne un `Eml`, indexé par dossier. Jeu de données couvrant dossier accentué et message sans date. |
| L1.3 | Constructeurs harmonisés | Absorbé par `AbstractInput` / `AbstractOutput`. |
| L1.5 | `var_dump()` supprimés | Ils s'exécutaient à **chaque** commande et noyaient toute sortie. |
| L1.6 | `catch (Error)` → `catch (\Throwable)` | Visait `Mailbxzip\Cli\Out\Error`, inexistante. |
| L1.7 | Écriture morte de `emailArchivePath` | Remplacée par `AbstractOutput::archivePath()`, qui lève une exception explicite. |
| L5.0 | Interfaces formalisées | `InputHandlerInterface`, `OutputHandlerInterface`, plus les deux classes de base. |

### Livré en supplément — défauts découverts en cours de route

| Sujet | Résultat |
|---|---|
| **Normalisation des dossiers unifiée** | `getFolders()` et `getEmail()` passent par une seule méthode. Décodage UTF-7 modifié correct (`mb_convert_encoding(…, 'UTF7-IMAP')` au lieu de `imap_utf7_decode`, dépréciée et qui décodait vers ISO-8859-1). **Correctif de la perte silencieuse sur les dossiers accentués.** |
| **Traversée de répertoire** | `AbstractInput::folderName()` neutralise les segments `..`, les `\0` et les segments vides : une source hostile ne peut plus faire écrire hors de l'archive. |
| **Résilience par message** | `process()` capture l'échec d'un message, le journalise, **laisse son UID hors de `saved_emails.json`** et poursuit. Le message est retenté au run suivant ; un compteur d'échecs est reporté en fin d'export. |
| **Repli en cascade** | Le repli écrit dans `<dossier>/.eml/`, donc il échoue quand le dossier est le problème — et levait un fatal qui interrompait tout l'export. Il remonte désormais proprement. |
| **`Eml::filename()`** | Même `catch` mort (`Mailbxzip\Cli\Exception`) ; de plus `new DateTime('')` vaut « maintenant », donc un message sans en-tête `Date` était classé à la date du jour au lieu de basculer sur `email-<uid>`. |
| **Validation de `in`/`out`** | `Unknown in connector 'Gmail'. Available: Imap, Test.` au lieu d'un « Class not found ». |
| **`Out/Test`** | Le type-hint non qualifié `Mailbox` se résolvait en `Out\Mailbox`. Réparé : `config.example.ini` fonctionne tel quel. |
| **Corps absent des PDF en texte brut** | Découvert en exécutant enfin `Out/Pdf`. Le gabarit `views/pdf/mail.html` ne rendait que `htmlBody` : **un message sans partie HTML produisait un PDF réduit à ses en-têtes**. Beaucoup de courrier réel est en `text/plain` seul. Repli ajouté sur le corps texte, sauts de ligne préservés. |
| **Troncature UTF-8 des noms** | `sanitizeFilename()` coupait à 50 **octets** (`substr`), ce qui pouvait scinder un caractère accentué et produire un nom invalide. Passé en `mb_substr`. |

### Reliquat

| # | Tâche | Détail | Charge |
|---|---|---|---|
| L1.4 | Corriger `getArchivesPath()` / `getTmpPath()` | Lisent les clés `archives` / `tmp`, alors que la configuration stocke `archives_dir` / `tmp_dir` → retour `null`. Non traité car ces accesseurs ne sont appelés nulle part ; à corriger ou à supprimer. | 0,25 j |

### ✅ Vérification levée

**La sortie PDF est désormais exercée de bout en bout.** `ext-gd` ayant été
installée, `Out/Pdf` a été validé à l'exécution : 9 documents produits, en-têtes
et corps rendus, accents préservés dans les noms comme dans le contenu, suffixe
de collision appliqué, et pièces jointes réellement embarquées (`/EmbeddedFile`,
`/FileAttachment`). Le harnais couvre ce chemin sous `composer run test-pdf`.

**Un défaut a été trouvé à cette occasion** — voir ci-dessous.

---

## L8 — Sortie de `ext-imap` ✅ **Livré**

**Justification.** PHP a **déprécié et sorti du cœur** l'extension `imap` en
8.4 : la bibliothèque C sous-jacente, `c-client`, n'a plus été mise à jour
depuis 2007, sa dernière version n'est plus distribuée, et elle ne fonctionne
plus avec G Suite. Le [RFC `unbundle_imap_pspell_oci8`](https://wiki.php.net/rfc/unbundle_imap_pspell_oci8)
est passé 27 voix contre 0. Aucun paquet `php-imap` n'existe dans les dépôts
Arch, et il n'y a pas de `pecl` : sur cet environnement, l'ancien connecteur
**ne peut tout simplement plus fonctionner**.

### Choix

Le manuel PHP ne recommande rien, mais le RFC nomme deux remplaçants :
`webklex/php-imap` et `zetacomponents/mail`. `ddeboer/imap` est écarté (il
enveloppe `ext-imap`), `laminas/laminas-mail` aussi (plafonné à PHP 8.3).

**`webklex/php-imap` retenu** : client IMAP dédié en PHP pur, API dossiers/UID
solide, OAuth2 pour le futur connecteur Gmail. Prix payé : une dizaine de
dépendances transitives.

### Livré

| # | Tâche | Résultat |
|---|---|---|
| L8.1 | Connecteur `In/Imap` réécrit | ~230 lignes sur `AbstractInput`. Recherche d'UID au niveau protocole (`UID SEARCH`) plutôt que via le constructeur de requêtes, qui téléchargerait tous les en-têtes. Message reconstitué par `RFC822.HEADER` + `RFC822.TEXT`, transposition directe de l'ancien `imap_fetchheader` + `imap_body`. |
| L8.2 | Compatibilité des configurations | La chaîne `server = "{ssl0.ovh.net:993/imap/ssl}"` propre à `ext-imap` est toujours comprise (hôte, port, `ssl`/`tls`/`starttls`/`notls`, `novalidate-cert`), en plus des clés explicites `host` / `port` / `encryption` / `validate_cert`. **Aucune configuration existante à réécrire.** |
| L8.3 | Bascule des noms | **`in = Imap` désigne désormais le module en PHP pur** : les configurations existantes en bénéficient sans être touchées. L'ancienne implémentation devient `in = ImapLegacy`, marquée dépréciée, et refuse de démarrer avec un message explicite quand `ext-imap` manque. |
| L8.4 | `ext-imap` rendue optionnelle | Passée de `require` à `suggest`. **`composer install` fonctionne désormais sans aucun `--ignore-platform-req`.** |
| L8.5 | Serveur IMAP de test | `tests/fake-imap-server.py` : serveur minimal en Python parlant `CAPABILITY`, `LOGIN`, `LIST`, `SELECT`/`EXAMINE`, `UID SEARCH`, `UID FETCH` avec littéraux et plages. Le harnais le démarre sur un **port libre attribué par le noyau**, l'arrête tout seul, et l'ignore proprement si `python3` manque. |

### Défaut trouvé grâce à ce serveur de test

Le nouveau connecteur **reproduisait l'invariant cassé que L1 avait corrigé** :
`getFolderByPath()` renvoie `null` sur un chemin encodé, et mon repli écrivait
les messages dans `INBOX.&AMk-l&AOk-ments envoy&AOk-s/` au lieu de
`INBOX/Éléments envoyés/`. La correspondance chemin brut → nom sur disque est
désormais **construite une fois depuis le listing et réutilisée**, ce qui rend
la divergence structurellement impossible et supprime au passage un aller-retour
réseau par message.

> C'est l'illustration de l'intérêt du lot L1 : le contrat et le harnais ont
> rendu ce connecteur écrivable, remplaçable et vérifiable.

### Reliquat

| # | Tâche | Détail | Charge |
|---|---|---|---|
| L8.6 | Éprouver sur une vraie boîte | Le serveur de test couvre le protocole, pas les particularités des serveurs réels (Dovecot, Exchange, OVH, LWS) : encodages exotiques, dossiers imbriqués profonds, gros volumes. **Le défaut ayant basculé, c'est désormais le point de vigilance principal.** | 0,5 j |
| ~~L8.7~~ | ~~Basculer le connecteur par défaut~~ | ✅ Livré. `in = Imap` utilise le module sans extension ; `config.example.ini` documente les deux ainsi que les clés de connexion et de filtre. | — |
| L8.8 | Évaluer le poids réel | `webklex` tire `illuminate/pagination`, `symfony/http-foundation` et `carbon` — 10 paquets pour un outil CLI. Mesurer, et arbitrer face à un client IMAP minimal maison (~250 lignes, zéro dépendance). | 0,25 j |
| L8.9 | Retirer `In/ImapLegacy` | Une fois L8.6 concluant sur les serveurs réels, l'ancien connecteur et la suggestion `ext-imap` n'ont plus de raison d'être. | 0,1 j |

---

## L9 — Filtre de date à l'entrée ✅ **Livré**

**Justification.** Aucun filtrage n'existait : `getEmails()` faisait un
`UID SEARCH ALL`. Impossible de découper un gros export en tranches, ni de
n'archiver qu'un exercice comptable.

**Le filtre appartient au `in`** : IMAP sait le faire côté serveur, qui ne
renvoie alors que les UID correspondants — rien d'inutile n'est téléchargé.
Filtrer côté `out` aurait imposé de rapatrier toute la boîte pour en jeter la
majeure partie.

### Configuration

| Clé | Rôle |
|---|---|
| `since` | N'exporter que les messages envoyés **à partir de** cette date (incluse), `AAAA-MM-JJ`. |
| `before` | N'exporter que les messages envoyés **strictement avant** cette date, `AAAA-MM-JJ`. |

Une date illisible ou une fenêtre vide (`since >= before`) est rejetée avec un
message explicite.

### Conception

L'analyse, la validation et la comparaison vivent dans `AbstractInput`
(`dateRange()`, `withinDateRange()`, `imapDate()`) : aucun connecteur ne les
réécrit. Chaque connecteur choisit comment les appliquer.

| Connecteur | Application |
|---|---|
| `In/Imap` | Poussé au serveur : `UID SEARCH SENTSINCE 01-Jan-2024 SENTBEFORE 01-Jan-2025`. |
| `In/ImapLegacy` | Idem via `imap_search()`. **Non exercé** — `ext-imap` n'est pas installable ici. |
| `In/Test` | Tri local sur l'en-tête `Date`, comme le feront `Mbox` et `Pst`. |

**`SENTSINCE`/`SENTBEFORE` et non `SINCE`/`BEFORE`** : le premier couple compare
l'en-tête `Date:`, le second la date interne au serveur. Une boîte migrée d'un
hébergeur à l'autre porte une date interne égale à la date de migration — tout
le courrier ancien paraîtrait récent, et le filtre contredirait le nommage des
fichiers, bâti sur cette même en-tête.

### Points de comportement

- Les compteurs par dossier suivent le filtre, donc la progression reste juste.
  Les dossiers vidés par le filtre sont **tout de même créés** : la structure de
  la boîte est préservée.
- Un message sans en-tête `Date` exploitable est **exclu d'un export filtré**
  — on ne peut pas affirmer qu'il est dans la fenêtre — mais **jamais en
  silence** : un `WARNING` est journalisé. Sans filtre, il reste exporté.
- La reprise fonctionne : élargir la fenêtre et relancer n'importe que le delta.
  **Rétrécir la fenêtre ne supprime rien** de ce qui est déjà archivé.

### Défaut trouvé pendant les tests

La comparaison portait d'abord sur des instants. `Thu, 1 Feb 2024 10:00:00 +0100`
devenait, une fois l'heure remise à zéro, minuit **en +01:00** — soit 23 h UTC
le 31 janvier — et se retrouvait classé *avant* le 1er février. **Une journée
entière de messages basculait du mauvais côté à chaque borne.** La comparaison
se fait désormais sur le jour calendaire, ce qui est aussi la sémantique du
protocole : IMAP ignore l'heure et le décalage.

### Reliquat

| # | Tâche | Détail | Charge |
|---|---|---|---|
| L9.1 | Éprouver `In/ImapLegacy` | Le filtre y est implémenté à l'identique mais non exercé, faute de `ext-imap`. À faire uniquement si ce connecteur déprécié est conservé. | 0,25 j |

---

## L10 — Sortie HTML et purge de la source ✅ **Livré**

**Justification.** Répondre au besoin « archiver les e-mails de plus de deux
ans en HTML, avec ou sans suppression ». Deux des trois briques manquaient :
`Out/Html` était un fichier vide, et rien ne supprimait quoi que ce soit — la
constante `CAN_DELETE`, déclarée par quatre classes, n'était lue nulle part.

### Livré

| # | Tâche | Résultat |
|---|---|---|
| L10.1 | Connecteur `Out/Html` | Archive navigable : une page par message, un index par dossier, un index racine, pièces jointes écrites dans un sous-dossier `<message>_files/` et liées depuis la page. Repli sur le corps texte quand le message n'a pas de partie HTML. |
| L10.2 | Index résistants à la reprise | Les index sont bâtis depuis un manifeste `.html-index.json` tenu dans l'archive, pas depuis l'exécution courante : un export repris liste toujours les messages écrits auparavant. |
| L10.3 | Suppression de la source | `delete = 1`, via l'interface optionnelle `DeletableInputInterface`. Implémentée par `Imap` (`STORE \Deleted` groupé puis un seul `EXPUNGE` par dossier) et `ImapLegacy`. |
| L10.4 | `CAN_DELETE` remise en service | La constante morte trouve enfin son rôle, celui pour lequel elle avait manifestement été écrite : l'entrée déclare qu'elle sait supprimer, la sortie se porte garante de son archive. `Out/Test`, qui n'écrit rien, ne la déclare pas — une purge après un essai à blanc est donc impossible. |
| L10.5 | Dossier `examples/` | Six configurations commentées et un README expliquant chacune, les clés disponibles, la reprise et la procédure de purge. |
| L10.6 | Option corbeille | `trash = 1` déplace les messages archivés vers la corbeille du serveur au lieu de les effacer. Le dossier est trouvé par l'attribut `\Trash` de SPECIAL-USE (RFC 6154), à défaut par les noms usuels ; il peut aussi être nommé, avec l'un ou l'autre séparateur. |

### Modèle de sûreté de la suppression

Quatre conditions, toutes nécessaires :

1. `delete = 1` explicitement dans la configuration ; absente par défaut.
2. Le connecteur d'entrée implémente `DeletableInputInterface` **et** déclare
   `CAN_DELETE = true`.
3. Le connecteur de sortie déclare `CAN_DELETE = true`.
4. **L'archive ZIP existe et n'est pas vide.** Sinon la purge est abandonnée et
   l'incident journalisé.

La suppression est la **toute dernière étape** de l'export, jamais entrelacée
avec l'écriture. Les messages visés sont lus depuis `saved_emails.json`, pas
depuis la seule exécution courante : un export repris purge aussi ce que les
exécutions précédentes avaient écrit. `purged_emails.json` évite de redemander
deux fois la même suppression.

Une combinaison interdite s'arrête avec un message explicite plutôt que de
supprimer.

### La corbeille comme filet

`trash` ajoute trois garanties au modèle ci-dessus :

- **Corbeille introuvable → aucune suppression.** L'export s'arrête sur un
  message explicite plutôt que de se rabattre sur un effacement définitif,
  c'est-à-dire sur l'inverse de ce qui a été demandé.
- **Copie échouée → aucun effacement** du dossier d'origine. Le message reste
  en place et sera retenté ; `purged_emails.json` ne l'enregistre pas.
- **Message déjà dans la corbeille → effacé sur place**, l'y déplacer n'ayant
  pas de sens.

Sur `ImapLegacy`, la détection ne peut pas lire les attributs SPECIAL-USE et se
limite aux noms usuels.

### Ce que le modèle ne couvre pas

- Sans `trash`, il n'y a pas de filet : les messages sont marqués `\Deleted`
  puis expurgés.
- L'outil vérifie que le ZIP a été écrit, **pas qu'il est lisible**. D'où la
  procédure en deux temps recommandée dans `examples/README.md`.

### Vérifié

Export complet vers un serveur IMAP de test avec `before = "2025-01-01"` et
`delete = 1` : les 4 messages de la fenêtre archivés puis supprimés, et **le
message hors fenêtre laissé intact** — le filtre de dates et la purge se
composent correctement.

---

## L0 — Mise en sécurité des secrets 🔴

**Justification.** Des identifiants circulent en clair dans les fichiers de
configuration. Deux situations distinctes :

- `cli/config/g.robin@thulium-engineering.com` est **suivi par Git et présent
  dans `origin/main`**, dépôt public. `.gitignore` ne le couvre pas : la règle
  n'a aucun effet sur un fichier déjà suivi. Selon que le compte a été fermé
  après archivage (usage normal de l'outil) ou non, c'est du ménage ou une
  fuite — **à confirmer par le mainteneur**.
- Les secrets de `_legacy-zip/` sont **locaux uniquement**, jamais suivis.

Indépendamment de ce statut, L0.3 et L0.4 restent des corrections structurelles
à faire.

| # | Tâche | Détail | Charge |
|---|---|---|---|
| L0.1 | Renouveler les identifiants encore actifs | À faire **uniquement pour les comptes non fermés**. Concerne les 5 boîtes (`_legacy-zip/config/*.ini`, `cli/config/g.robin@…`), le mot de passe partagé en dur dans `_legacy-zip/cli/cli.php` et la clé d'API Jirafeau (`_legacy-zip/cron.php`). | 0,5 j |
| L0.2 | Retirer le fichier de configuration du dépôt | `git rm --cached cli/config/g.robin@…` suffit pour le HEAD ; une réécriture d'historique (`git filter-repo`) n'est justifiée que si l'identifiant est encore actif. | 0,25 j |
| L0.3 | Sortir les mots de passe du fichier INI | Résolution en cascade : variable d'environnement → fichier de secrets à droits restreints → invite interactive. Le mot de passe ne doit plus jamais être réécrit par `updateConfig()`. **Se simplifie nettement si L2.1 est fait d'abord** (le fichier INI redevient lecture seule). | 0,5 j |
| L0.4 | Durcir les permissions | `0700`/`0750` au lieu de `0777` dans `Mailbox::createDirectories()` et `AbstractOutput::ensureDirectory()` — désormais **deux points d'appel au lieu de six**, grâce aux classes de base. Attention si un serveur web doit lire les archives. | 0,25 j |

**Critère d'acceptation.** Aucun fichier de configuration suivi par Git ; un
export fonctionne avec le mot de passe fourni par l'environnement.

---

## L2 — Fiabilisation du traitement 🟠

**Justification.** Les deux causes de perte de données sont fermées (dossiers
accentués en L1, collisions de noms en L2.3) et la sortie MBOX est désormais
relisible. Reste le risque de corruption de l'état, et la robustesse réseau.

| # | Tâche | Détail | Charge |
|---|---|---|---|
| L2.1 | Dissocier configuration et état | Fichier d'état distinct (`<archive>/state.json`) pour `state`, `progress`, `start_time`, `end_time`. Le fichier INI redevient une entrée en lecture seule : commentaires préservés, plus de réécriture intégrale à chaque message. **Prérequis confortable de L0.3 et L3.4.** | 1,5 j |
| L2.2 | Écriture atomique de l'état | Fichier temporaire puis `rename()`, pour `state.json` comme pour `saved_emails.json` (`Mailbox.php:254`, réécrit après chaque message). Supprime le risque de corruption sur interruption. | 0,5 j |
| ~~L2.3~~ | ~~Collisions de noms de fichiers~~ | ✅ Livré. Suffixe par UID plutôt que par compteur, pour qu'une archive reconstruite retrouve les mêmes noms ; collision journalisée en `WARNING`. Appliqué à `Out/Eml`, `Out/Pdf` et aux sources `wSource`. | — |
| ~~L2.4~~ | ~~Sortie MBOX conforme~~ | ✅ Livré. Variante **mboxrd** : ligne `From <expéditeur> <date asctime>` et échappement `>From` / `>>From`. Octets du message préservés à l'identique par ailleurs. | — |
| L2.5 | Reconnexion IMAP automatique | Détecter la perte de connexion, réessayer avec temporisation exponentielle. Moins critique depuis la résilience par message (l'export n'est plus interrompu, les messages manqués sont retentés), mais une coupure durable fait encore échouer toute la suite du run. | 1 j |
| L2.6 | Corriger le découpage HTML | `chunkHtml()` retire le séparateur lors du `explode()`, altérant la mise en page des messages volumineux. Réinjecter le séparateur et regrouper par taille cible. | 0,5 j |

**Critère d'acceptation.** Export d'une boîte de référence (> 5 000 messages,
dossiers imbriqués, accents, pièces jointes volumineuses) sans perte ni
écrasement ; interruption brutale puis relance sans corruption.

> Reste à valider sur le terrain : la réouverture d'une archive MBOX produite
> par L2.4 dans un vrai client (Thunderbird), sur une boîte réelle.

---

## L3 — Pilotage réel de l'export 🟠

**Justification.** `--stop`, `--pause` et `--daemon` sont annoncés dans l'aide
mais sans aucune implémentation. Prérequis à tout pilotage externe (L6).

| # | Tâche | Détail | Charge |
|---|---|---|---|
| L3.1 | Verrou d'exécution | Empêcher deux exports concurrents sur la même boîte. `symfony/lock` est **déjà déclaré en dépendance et inutilisé**. | 0,75 j |
| L3.2 | Implémenter `--stop` et `--pause` | Signal par fichier sentinelle ou verrou ; le point de contrôle naturel est la boucle de `process()`, entre deux messages — l'index de reprise garantit déjà la cohérence. | 1,5 j |
| L3.3 | Implémenter `--daemon` | Détachement du processus, écriture d'un PID, redirection du journal. | 1 j |
| L3.4 | Statut lisible par un tiers | Exposer `state.json` (statut, progression, ETA, PID, dernier message traité) au format stable. Dépend de L2.1. | 0,75 j |

---

## L4 — Commande `config` opérationnelle 🟡

**Justification.** `--addConfig`, `--listConfig`, `--stateConfig` sont déclarées
mais vides ; seule la génération d'aide fonctionne. La création d'une boîte se
fait à la main.

| # | Tâche | Détail | Charge |
|---|---|---|---|
| L4.1 | `--addConfig` | Création assistée, avec validation de l'adresse et **test de connexion** avant écriture. Reprendre la validation du prototype (`_legacy-zip/cli/src/Mailbxzip.php::addConfig()`). | 1 j |
| L4.2 | `--listConfig` | Liste des boîtes configurées avec leur statut. | 0,5 j |
| L4.3 | `--stateConfig` | Détail de l'avancement d'une boîte. Dépend de L3.4. | 0,5 j |
| L4.4 | Corriger le routage des options | La branche `--start` (inexistante sur cette commande) et `--option` sont mal câblées ; `getOption('help')` court-circuite l'aide Symfony. | 0,5 j |
| L4.5 | Bibliothèque de serveurs connus | Raccourcis `OVH`, `LWS`, `Gmail`… au lieu de la chaîne IMAP brute, comme dans le prototype. | 0,5 j |

---

## L5 — Connecteurs manquants 🟡

**Socle livré (L5.0).** Le contrat est désormais explicite et vérifié à
l'instanciation ; `AbstractInput` et `AbstractOutput` absorbent le constructeur,
l'accès configuration, les crochets, la création de dossiers, l'écriture
gardée, le repli et la normalisation des noms de dossiers. **Un nouveau
connecteur se réduit à sa logique propre** — les sorties réelles font une
quarantaine de lignes.

| # | Tâche | Détail | Charge |
|---|---|---|---|
| ~~L5.0~~ | ~~Formaliser le contrat~~ | ✅ Livré. | — |
| L5.1 | Entrée `In/Mbox` | Lecture d'un fichier MBOX local. Le plus simple, et complète l'aller-retour avec la sortie MBOX (à faire après L2.4, sinon il n'y a rien de conforme à relire). | 1,5 j |
| ~~L5.2~~ | ~~Sortie `Out/Html`~~ | ✅ Livré avec L10. | — |
| L5.3 | Sortie `Out/Csv` | Index tabulaire (date, de, à, objet, dossier, pièces jointes), utile en complément d'un autre format pour la recherche. | 1 j |
| L5.4 | Entrée `In/Gmail` | API Gmail + OAuth2 : consentement, jetons de rafraîchissement, quotas. **Allégé par L8** : `webklex/php-imap` gère déjà OAuth2, l'IMAP de Gmail peut suffire. | 3 j |
| L5.5 | Entrée `In/Pst` | Archives Outlook, via `libpff` ou une bibliothèque PHP. **Étude de faisabilité à mener d'abord** : pas de solution PHP évidente. | 2 j (+ étude) |

**Critère d'acceptation.** Chaque connecteur livré étend sa classe de base,
déclare ses constantes `HELP` / `MINIMAL_CONFIG_VAR`, apparaît dans l'aide
générée, et est couvert par le harnais de test.

---

## L6 — Reprise des acquis du prototype 🔵

**Justification.** Le prototype `_legacy-zip/` a exploré une chaîne complète
« archivage → mise à disposition → notification » absente du dépôt.
**Ce code n'est pas réutilisable tel quel** (il dépend d'un `ImapArchiver.php`
absent et repose sur l'architecture monolithique abandonnée) : il s'agit de
réimplémenter les fonctions sur l'architecture actuelle.

> ⚠️ Ce lot n'a de sens que si le produit vise un usage en service.
> **Arbitrage à rendre avant tout engagement.**

| # | Tâche | Détail | Charge |
|---|---|---|---|
| L6.1 | Cycle de vie explicite | `pending` → `import` → `zip` → `ready` / `error`. La clé `status` de `config.example.ini` est un vestige de ce mécanisme, aujourd'hui non lue. | 1 j |
| L6.2 | Traitement par lots planifié | Équivalent de `cron.php` : sélection de la prochaine boîte en attente, export, passage au statut suivant. Repose sur L3.1. | 1,5 j |
| L6.3 | Archivage de la demande traitée | Basculer la configuration en `.ini_bak` ou changer son statut, pour qu'elle ne soit pas rejouée. | 0,5 j |
| L6.4 | Livraison de l'archive | Dépôt du ZIP sur un service de partage (Jirafeau dans le prototype), en **connecteur enfichable** plutôt qu'en dur. | 2 j |
| L6.5 | Notification par e-mail | Envoi du lien de téléchargement au demandeur, gabarit Twig. | 1 j |
| L6.6 | Découpage du ZIP | `MAX_ZIP_SIZE_MB` et `getZipPaths()` existent dans le prototype mais **le code de découpage n'a jamais été écrit** (commentaire « … reste du code existant »). À réaliser intégralement. | 2 j |
| L6.7 | API HTTP | `add`, `status`, `getzip`, `downloadzip` derrière authentification. Prérequis : L3 et L6.1. | 3 j |
| L6.8 | Distribution en PHAR | Reprendre `_legacy-zip/cli/build.php`. | 1 j |

**Point de vigilance.** Le prototype désactivait la vérification SSL
(`CURLOPT_SSL_VERIFYPEER = false`) et codait la clé d'API en dur : à ne pas
reproduire.

---

## L7 — Qualité, tests et documentation 🟢

**Amorcé.** `cli/tests/smoke.php` couvre le pipeline de bout en bout en PHP nu,
sans dépendance ajoutée : Eml/Mbox/Pdf/Test, dossiers accentués, règle de
nommage, collisions, format mbox, gabarit PDF, pièces jointes, reprise,
`wSource`, validation des connecteurs, résilience aux écritures impossibles,
sûreté des noms de dossiers.

- `composer test` — **106 vérifications** (la section PDF s'annonce ignorée)
- `composer run test-pdf` — **112 vérifications**, avec `ext-gd` chargée

| # | Tâche | Détail | Charge |
|---|---|---|---|
| ~~L7.4~~ | ~~Élaguer les dépendances~~ | ✅ Livré. `dompdf`, `tcpdf`, `fpdi`, `fpdf`, `setasign/fpdf` retirés — 5 dépendances directes restantes. Le plafond PHP 8.4 imposé par `sabberworm/php-css-parser` (dépendance de dompdf) est levé : l'installation fonctionne sur PHP 8.5. `setasign/fpdi` reste présent, tiré transitivement par mPDF qui s'en sert pour les annotations. | — |
| ~~L7.5~~ | ~~Déclarer les extensions requises~~ | ✅ Livré. `php >=8.1`, `ext-dom`, `ext-imap`, `ext-mbstring`, `ext-zip` déclarés ; licence corrigée en SPDX `GPL-3.0-or-later` ; raccourci `composer test` ajouté. | — |
| L7.1 | Passer le harnais sous PHPUnit | `smoke.php` fait office de filet mais n'offre ni isolation, ni rapport, ni intégration outillée. | 1 j |
| L7.2 | Étendre les tests unitaires `Eml` | Décodage, pièces jointes, cas limites de la règle de nommage (collisions — cf. L2.3, en-têtes absents, encodages exotiques). | 0,5 j |
| L7.3 | Étendre les tests d'intégration | ~~Couvrir `Out/Pdf`~~ ✅ fait. Reste le comportement sur interruption en cours d'écriture, et le découpage des très gros messages (cf. L2.6). | 0,25 j |
| L7.6 | README d'exploitation | Installation, prérequis, format de configuration, exemples, procédure de reprise, **guide d'écriture d'un connecteur**. Le README actuel tient en une ligne. | 1 j |
| L7.7 | Intégration continue | Tests et analyse statique (PHPStan) sur chaque *push*. | 0,5 j |

---

## Ce qui n'est **pas** repris du prototype

| Élément | Motif |
|---|---|
| `_legacy-zip/src/*` et `_legacy-zip/cli/src/*` | Architecture monolithique `Ycdev\Mailbxzip`, antérieure et abandonnée. |
| `index.php`, `api.php`, `cron.php` en l'état | Dépendent de `src/ImapArchiver.php`, absent de l'archive : non exécutables. |
| Mot de passe partagé pour toutes les boîtes | Défaut de conception majeur (`_legacy-zip/cli/cli.php`). |
| `getFirstConfig()` | Traite une seule boîte à la fois, sans file d'attente. |
| Moteur PDF dompdf | Remplacé par mPDF, qui gère les annotations de pièces jointes. |

---

## Recommandation de séquencement

**~~Sprint 1 — Assainir~~** ✅ Livré (L1 + L5.0).

**~~Sprint 2 — Fermer les pertes~~** ✅ Livré (L2.3, L2.4, L7.4, L7.5).

**~~Sprint 3 — Sortir de `ext-imap`~~** ✅ Livré (L8).

**Sprint 4 — Assainir l'état (≈ 3,5 j).** **L2.1** puis **L2.2**, puis **L0.3**
et **L0.4** : dissocier configuration et état sort naturellement le mot de
passe du fichier réécrit à chaque message, et débloque L3.4.

**Sprint 5 — Rendre exploitable (≈ 7 j).** L3 et L4 : l'outil devient pilotable
au quotidien.

**Sprint 6 et suivants.** L5 (valeur fonctionnelle directe) et/ou L6 (mise en
service), selon l'orientation produit retenue.

> **Note d'environnement.** `ext-gd` est présente sur la machine mais **non
> activée** dans `/etc/php/php.ini` : les commandes PDF passent par
> `php -d extension=gd`. Ajouter `extension=gd` au `php.ini` rendrait le
> raccourci `composer test` complet, et `composer install` fonctionnel sans
> `--ignore-platform-req`. `ext-imap` n'est en revanche pas installée du tout,
> le connecteur `In/Imap` reste donc non exercé.
