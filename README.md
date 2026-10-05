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
2. Onglet **Bot** : **Reset Token**, copier le jeton. Désactiver « Public Bot ».
3. Onglet **Installation** (ou **OAuth2 > URL Generator**) : portée `bot`, permissions
   « Voir les salons », « Envoyer des messages », « Intégrer des liens », et « Gérer les
   rôles » pour le rôle « Adhérent » automatique. Ouvrir le lien
   généré et ajouter l'application au serveur Nyassobi.
4. Dans Discord, activer le mode développeur (Paramètres > Avancés), puis clic droit pour
   **Copier l'identifiant** du salon privé du CA et du rôle CA. Vérifier que le bot a accès
   au salon.
5. Dans WordPress, **Adhésions > Réglages** : renseigner l'ID de l'application, la clé
   publique (onglet General Information), le jeton du bot, l'ID du salon, l'ID du rôle CA,
   le nombre de membres du CA, le lien de paiement HelloAsso et l'e-mail du bureau.
6. De retour sur le portail développeur, onglet **General Information**, coller dans
   **Interactions Endpoint URL** l'adresse affichée sur la page de réglages
   (`https://admin.nyassobi.fr/wp-json/nyassobi/v1/discord`) et enregistrer : Discord
   vérifie l'adresse sur-le-champ, la sauvegarde échoue si quelque chose cloche.

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
données de WordPress. Sans paiement, une relance part après 7 jours et la demande expire
après 30 (réglables), avec effacement des données.

### Rôle « Adhérent » sur Discord

Facultatif : renseigner l'ID du serveur, celui du rôle « Adhérent » et une invitation. Le
formulaire propose alors de donner son pseudo Discord, et le rôle est donné dès le paiement.
Le bot doit avoir la permission **Gérer les rôles**, et son propre rôle doit être placé
**au-dessus** du rôle « Adhérent » dans la liste des rôles du serveur. Si le pseudo n'est pas
trouvé (pas encore sur le serveur, faute de frappe), le message du CA le signale et l'e-mail
de bienvenue contient l'invitation.

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
