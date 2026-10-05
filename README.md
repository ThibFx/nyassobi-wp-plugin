# Nyassobi WP Plugin

Plugin WordPress leger qui centralise les reglages necessaires au front-end headless de Nyassobi et les expose a WPGraphQL.

## Installation

1. Copier le dossier `nyassobi-wp-plugin` dans `wp-content/plugins/`.
2. Dans l'admin WordPress, activer **Nyassobi WP Plugin**.

## Utilisation

Ouvrir **Reglages > Parametres Nyassobi** puis renseigner :

- Adresse email de contact.
- Adresse d'expédition des e-mails (en `@nyassobi.fr`, pour ne pas partir en spam).
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
