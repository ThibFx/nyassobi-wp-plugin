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
   « Voir les salons », « Envoyer des messages », « Intégrer des liens ». Ouvrir le lien
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
`reducedRate`, `acceptsRules`, `acceptsPrivacy`. Trois demandes par heure au plus depuis une
même connexion, et une seule demande en cours par adresse e-mail.

### Bac à sable

Pour essayer tout le circuit sans rien envoyer, un WordPress de test tourne sur la Raspberry Pi :

```bash
./scripts/bac-a-sable.sh        # Mac / Linux
.\scripts\bac-a-sable.ps1       # Windows
```

- `http://<pi>:8504/bac-a-sable/` : Discord simulé (on vote à la place des 6 membres du CA,
  ou d'une personne hors CA) et les e-mails qui seraient partis. Bouton de remise à zéro.
- `http://<pi>:8504/wp-admin/` : la vue du bureau. Identifiants dans
  `~/nyassobi-bac-a-sable/IDENTIFIANTS.txt` sur la Pi.
- `http://<pi>:8505/` : la copie du site branchée sur ce WordPress, déployée depuis le dépôt
  du site avec `./scripts/deployer.sh --bac-a-sable`. Elle affiche les vrais contenus, mais
  ses formulaires (adhésion, contact) partent vers le bac à sable.

Le mu-plugin `bac-a-sable/mu/bac-a-sable.php` intercepte Discord et tous les e-mails : il ne
doit jamais être installé sur le vrai WordPress.
