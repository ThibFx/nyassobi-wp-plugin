# Nyassobi WP Plugin

Plugin WordPress leger qui centralise les reglages necessaires au front-end headless de Nyassobi et les expose a WPGraphQL.

## Installation

1. Copier le dossier `nyassobi-wp-plugin` dans `wp-content/plugins/`.
2. Dans l'admin WordPress, activer **Nyassobi WP Plugin**.

## Utilisation

Ouvrir **Reglages > Parametres Nyassobi** puis renseigner :

- Adresse email de contact.
- URL du formulaire d'inscription.
- URL de l'accord parental.
- URL des statuts associatifs.
- URL du reglement interieur.

Les valeurs sont accessibles en PHP via :

```php
$settings = Nyassobi_WP_Plugin::get_settings();
```

### WPGraphQL

Si le plugin [WPGraphQL](https://www.wpgraphql.com/) est actif, une requete `nyassobiSettings` devient disponible :

```graphql
query GetNyassobiSettings {
  nyassobiSettings {
    contactEmail
    signupFormUrl
    parentalAgreementUrl
    associationStatusUrl
    internalRulesUrl
  }
}
```

Toutes les cles retournees peuvent etre `null` si aucune valeur n'a encore ete renseignee.

### Mutation de contact

Mutation d'envoi de message :

```graphql
mutation SendNyassobiContact($input: SendNyassobiContactMessageInput!) {
  sendNyassobiContactMessage(input: $input) {
    success
    message
  }
}
```

Variables :

```json
{
  "input": {
    "fullname": "Nom Prenom",
    "email": "vous@example.com",
    "subject": "Objet",
    "message": "Contenu du message"
  }
}
```

Envoyer un champ `token` optionnel dans `input` si vous branchez un anti-spam (reCAPTCHA, nonce, etc.). Utiliser le filtre `nyassobi_wp_plugin_validate_contact_token` pour verifier ce jeton avant l'envoi. Des filtres supplementaires (`nyassobi_wp_plugin_contact_*`) permettent aussi d'ajuster destinataire, sujet, corps ou en-tetes du courriel.

## Adhésions validées par le CA sur Discord

Le formulaire d'adhésion du site envoie la demande à WordPress, qui la soumet au vote du
conseil d'administration dans un salon Discord privé. Aucun bot n'a besoin de tourner :
Discord appelle directement WordPress à chaque clic sur un bouton.

1. La personne remplit le formulaire (pseudo, prénom, nom, date de naissance, e-mail, tarif).
2. WordPress enregistre la demande (menu **Adhésions**, visible des seuls administrateurs)
   et lui envoie un accusé de réception.
3. Un message arrive dans le salon du CA avec **le pseudo seul** et trois boutons :
   Pour, Contre, Abstention. Chaque membre du CA a une voix, qu'il peut changer tant que la
   décision n'est pas prise.
4. La décision tombe dès qu'elle est acquise : acceptée à la moitié + 1 du CA (4 sur 6),
   refusée dès que ce seuil devient impossible à atteindre. Le pseudo disparaît alors du
   message (« Demande n°12 · Acceptée »).
5. Acceptée : la personne reçoit le lien de paiement (et la demande d'autorisation
   parentale si elle est mineure) ; le bureau est prévenu. Une fois la cotisation reçue et
   la personne inscrite au registre, le bouton **Finaliser et effacer** supprime ses données
   de WordPress. Refusée : la personne est prévenue et ses données sont effacées aussitôt.

Les demandes sans décision depuis 90 jours sont effacées automatiquement. Si Discord est
indisponible, le CA peut aussi accepter ou refuser depuis la fiche de la demande.

Tant que Discord n'est pas entièrement configuré, la mutation `submitNyassobiMembership`
n'est pas exposée et le site renvoie vers l'ancien formulaire d'adhésion.

### Mise en place (une seule fois)

1. Sur <https://discord.com/developers/applications>, **New Application** (« Nyassobi
   Adhésions » par exemple).
2. Onglet **Installation** : **Lien d'installation** sur « Aucun », enregistrer. Discord
   refuse sinon de rendre l'application privée à l'étape suivante.
3. Onglet **Bot** : **Reset Token**, copier le jeton. Désactiver « Public Bot ».
4. Onglet **OAuth2 > URL Generator** : portée `bot`, permissions « Voir les salons »,
   « Envoyer des messages », « Intégrer des liens », et « Gérer les rôles » pour le rôle
   « Adhérent » automatique. Ouvrir le lien généré en bas de page et ajouter
   l'application au serveur Nyassobi.
5. Dans Discord, activer le mode développeur (Paramètres > Avancés), puis clic droit pour
   **Copier l'identifiant** du salon privé du CA et du rôle CA. Vérifier que le bot a accès
   au salon.
6. Dans WordPress, **Adhésions > Réglages** : renseigner l'ID de l'application, la clé
   publique (onglet General Information), le jeton du bot, l'ID du salon, l'ID du rôle CA,
   le nombre de membres du CA, le lien de paiement HelloAsso et l'e-mail du bureau.
7. De retour sur le portail développeur, onglet **General Information**, coller dans
   **Interactions Endpoint URL** l'adresse affichée sur la page de réglages
   (`https://admin.nyassobi.fr/wp-json/nyassobi/v1/discord`) et enregistrer : Discord
   vérifie l'adresse sur-le-champ, la sauvegarde échoue si quelque chose cloche.

### Bureau et chiffrement des données

Dans **Adhésions > Réglages**, en bas de page : choisir les comptes WordPress du bureau (seuls
eux voient les demandes, pas les autres administrateurs) et le **mot de passe du bureau**. Nom,
prénom, date de naissance et autorisation parentale sont chiffrés dès leur arrivée (libsodium) :
ni un autre administrateur ni l'hébergeur ne peuvent les lire. Ils s'affichent sur la fiche, et
dans l'export Excel, après saisie de ce mot de passe. Pseudo et e-mail restent en clair : le site
en a besoin pour fonctionner seul. Tant que le mot de passe n'est pas choisi, le circuit reste
fermé. S'il est perdu, les demandes en cours deviennent illisibles (le registre, lui, est hors
ligne).

### Paiement de la cotisation

Une fois la demande acceptée, la personne reçoit un lien vers sa page personnelle sur le
site (`/cotisation/<jeton>`). Le paiement HelloAsso ou PayPal n'est créé qu'au clic : un
lien HelloAsso ne reste valable que 15 minutes, il ne peut donc pas partir par e-mail.

- **Carte bancaire, par HelloAsso** (sans frais pour l'asso). Dans le back-office HelloAsso,
  *Mon compte > Intégrations et API* : créer une clé API et recopier le client ID et le
  client secret dans **Adhésions > Réglages**, avec le nom de l'association tel qu'il
  apparaît dans l'adresse (`nyassobi`). Dans *Notifications*, indiquer l'adresse affichée
  sur la page de réglages (`…/wp-json/nyassobi/v1/helloasso`) : elle sert quand la personne
  ferme l'onglet avant de revenir sur le site. Chaque notification est revérifiée auprès de
  l'API HelloAsso (elles ne sont pas signées pour les associations).
- **PayPal** : désactivé tant que ses clés sont vides. Il faut un compte PayPal Business
  au nom de l'asso, puis sur <https://developer.paypal.com>, *Apps & Credentials* > *Live* :
  créer une application et recopier son client ID et son secret. PayPal prélève une
  commission sur chaque paiement, contrairement à HelloAsso.
- Sans clés HelloAsso, le lien de paiement de secours est proposé et le bureau marque la
  cotisation payée à la main depuis la fiche (bouton « Marquer la cotisation comme payée »,
  utile aussi pour un paiement en espèces en convention).
- Les deux ont un environnement de test (cases à cocher dans les réglages) : HelloAsso sur
  helloasso-sandbox.com avec des cartes fictives, PayPal avec des comptes fictifs.

Au paiement : la demande passe « Cotisation payée », la personne reçoit l'e-mail de
bienvenue, le message du CA affiche « cotisation reçue », et le bureau est prévenu (sans
donnée personnelle) pour l'inscrire au registre puis **finaliser**, ce qui efface ses
données de WordPress. Si personne ne finalise, le bureau reçoit un rappel 15 jours après le
paiement, et la demande est effacée automatiquement au bout de 30 jours (réglables) : les
données ne restent jamais en ligne. Sans paiement, une relance part après 7 jours et la
demande expire après 30 (réglables), avec effacement des données.

Pour le registre des membres (tenu hors ligne dans un fichier Excel) : dans **Adhésions**, le
bouton « Exporter … pour le registre (Excel) » télécharge les adhésions payées (nom, prénom,
date de naissance, e-mail, date d'adhésion, cotisation ; jamais le pseudo). Une fois les lignes
copiées dans le registre, cocher les demandes et choisir l'action groupée « Finaliser » :
leurs données sont effacées de WordPress. Seules les demandes déjà passées dans un export
peuvent être finalisées, pour ne jamais effacer quelqu'un qui n'est pas encore au registre.

**Fin de saison** : toutes les adhésions se terminent le 31 août. À la date réglée (15 août
par défaut), le bot annonce la nouvelle saison au rôle « Adhérent » dans le salon choisi, et le
bureau reçoit un e-mail l'invitant à ouvrir **Adhésions > Rappel de fin de saison** : il y colle
la colonne « E-mail » du registre, chaque adresse reçoit un rappel individuel, et rien n'est
enregistré dans WordPress. Un renouvellement est une nouvelle demande, votée par le CA.

Rien sur la personne n'est envoyé à HelloAsso : le payeur (souvent un parent, pour un
mineur) saisit lui-même ses coordonnées sur la page de paiement. Le lien avec la demande
passe par un numéro interne.

### Expéditeur des e-mails et spam

Renseigner dans **Adhésions > Réglages** une adresse d'expédition en `@nyassobi.fr`
(par exemple `adhesion@nyassobi.fr`). Sans elle, WordPress écrit au nom de « WordPress »
depuis `wordpress@admin.nyassobi.fr`, un domaine sans SPF : direction les spams. Les
réponses arrivent à l'adresse de contact des réglages Nyassobi.

Côté domaine (espace client OVH, *Noms de domaine > nyassobi.fr*) :

- **SPF** : déjà en place (`v=spf1 include:mx.ovh.com -all`), il couvre l'hébergement OVH.
- **DKIM** : à activer (*Emails > DKIM*), pour que les e-mails soient signés.
- **DMARC** : ajouter un enregistrement TXT `_dmarc` avec
  `v=DMARC1; p=none; rua=mailto:<adresse du bureau>`, puis passer à `p=quarantine` une
  fois les rapports propres. Gmail l'exige des expéditeurs depuis 2024.

Pour vérifier : envoyer un e-mail de test à l'adresse donnée par <https://www.mail-tester.com>.
Le plugin [WP Mail SMTP](https://wordpress.org/plugins/wp-mail-smtp/), configuré avec la
boîte OVH de l'adresse d'expédition, améliore encore la délivrabilité.

### Rôle « Adhérent » sur Discord

Facultatif : renseigner l'ID du serveur, celui du rôle « Adhérent » et une invitation. Le
formulaire propose alors de donner son pseudo Discord, et le rôle est donné dès le paiement.
Le bot doit avoir la permission **Gérer les rôles**, et son propre rôle doit être placé
**au-dessus** du rôle « Adhérent » dans la liste des rôles du serveur. Si le pseudo n'est pas
trouvé (pas encore sur le serveur, faute de frappe), le message du CA le signale et l'e-mail
de bienvenue contient l'invitation.

**Recommandé : le lien « Rejoindre le Discord ».** Discord ne laisse un bot donner un rôle
qu'à quelqu'un déjà sur le serveur. Avec le *client secret* de l'application (portail
développeur > OAuth2), l'e-mail de bienvenue et la page de cotisation proposent un bouton :
la personne autorise Nyassobi sur Discord, et rejoint le serveur avec le rôle en un clic
(ou reçoit juste le rôle si elle y était déjà). Le formulaire ne demande alors plus le pseudo.

1. Portail développeur > **OAuth2** : copier le **Client Secret** dans **Adhésions >
   Réglages**, et ajouter dans **Redirects** l'adresse affichée sous ce champ
   (`…/wp-json/nyassobi/v1/retour/discord`).
2. Le bot doit aussi pouvoir **Créer une invitation** (permission accordée par défaut à
   tout le monde sur un serveur).

Le lien est personnel, ne marche qu'une fois la cotisation payée, et seulement pour le
premier compte Discord qui l'utilise. L'accès donné par Discord sert à cette seule opération
puis est rendu ; WordPress garde le nom du compte jusqu'à la finalisation.

### Mutation

```graphql
mutation Join($input: SubmitNyassobiMembershipInput!) {
  submitNyassobiMembership(input: $input) {
    success
    message
  }
}
```

Champs de `input` : `pseudo`, `firstName`, `lastName`, `birthDate` (`AAAA-MM-JJ`), `email`,
`reducedRate`, `acceptsRules`, `acceptsPrivacy`, et facultatifs `discordUsername` et
`parentalAuthorization` (obligatoire pour les mineurs : `{ fileName, mimeType, base64 }`).
Requêtes associées : `nyassobiMembershipOpen`, `nyassobiMembershipFees`,
`nyassobiCotisation(token)`. Trois demandes par heure au plus depuis une
même connexion, et une seule demande en cours par adresse e-mail.

### Bac à sable

Pour essayer tout le circuit sans rien envoyer, un WordPress de test tourne sur la Raspberry Pi :

```bash
./scripts/bac-a-sable.sh        # Mac / Linux
.\scripts\bac-a-sable.ps1       # Windows
```

- `http://<pi>:8504/bac-a-sable/` : Discord simulé (on vote à la place des 6 membres du CA,
  ou d'une personne hors CA), les e-mails qui seraient partis, les cotisations en attente
  (boutons « +7 jours » et « +30 jours » pour voir la relance et l'expiration) et les rôles
  donnés. HelloAsso et PayPal sont remplacés par une page de paiement factice. Un pseudo
  Discord contenant « absent » simule quelqu'un qui n'est pas sur le serveur.
- `http://<pi>:8504/wp-admin/` : la vue du bureau. Identifiants dans
  `~/nyassobi-bac-a-sable/IDENTIFIANTS.txt` sur la Pi.
- `http://<pi>:8505/` : la copie du site branchée sur ce WordPress, déployée depuis le dépôt
  du site avec `./scripts/deployer.sh --bac-a-sable`. Elle affiche les vrais contenus, mais
  ses formulaires (adhésion, contact) partent vers le bac à sable.

Le mu-plugin `bac-a-sable/mu/bac-a-sable.php` intercepte Discord et tous les e-mails : il ne
doit jamais être installé sur le vrai WordPress.

### Pré-production

Comme le bac à sable, mais en vrai : un serveur Discord de test, de vrais e-mails (vers des
adresses autorisées seulement) et les environnements de test de HelloAsso et PayPal.

```bash
./scripts/preprod.sh                    # dépôt du plugin : WordPress de pré-production
./scripts/deployer.sh --preprod         # dépôt du site : la copie du site branchée dessus
```

Tout passe par Tailscale, en HTTPS ; les conteneurs n'écoutent que sur la Pi. Une seule fois,
sur la Pi :

```bash
sudo tailscale serve --bg --https=443 http://127.0.0.1:8507
sudo tailscale serve --bg --https=8443 http://127.0.0.1:8506
sudo tailscale funnel --bg --https=10000 --set-path=/wp-json/nyassobi/v1/discord http://127.0.0.1:8506/wp-json/nyassobi/v1/discord
sudo tailscale funnel --bg --https=10000 --set-path=/wp-json/nyassobi/v1/helloasso http://127.0.0.1:8506/wp-json/nyassobi/v1/helloasso
```

- `https://<nom Tailscale de la Pi>` : la copie du site ; `:8443` : l'administration WordPress
  (identifiants et mot de passe du bureau dans `~/nyassobi-preprod/IDENTIFIANTS.txt`).
- Seules les deux adresses du port 10000 sont publiques : c'est celle de `/discord` qu'il faut
  donner à Discord comme « Interactions Endpoint URL », et celle de `/helloasso` à HelloAsso
  comme adresse de notification.
- `~/nyassobi-preprod/.env` (sur la Pi, jamais dans Git) : compte d'envoi des e-mails
  (`SMTP_UTILISATEUR`, `SMTP_MOT_DE_PASSE` ; pour Gmail, un « mot de passe d'application ») et
  `DESTINATAIRES_AUTORISES` (par exemple `ton.adresse+*@gmail.com`). Tout autre destinataire est
  bloqué et signalé dans l'administration. Relancer `./scripts/preprod.sh` après une modification.
- Le CA de pré-production compte une personne : on peut voter seul (réglable).
