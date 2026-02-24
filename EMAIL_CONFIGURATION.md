# Configuration de l'envoi d'emails

## IMAP vs SMTP : quelle config pour les liens formulaires ?

- **SMTP** = **envoi** d’emails. C’est ce que l’API utilise pour envoyer le lien du formulaire au client.
- **IMAP** = **réception** d’emails (lire la boîte mail). Non utilisé par Laravel pour cet envoi.

Si vous configurez votre boîte mail (IMAP côté client mail), votre fournisseur propose en général **les deux** : un serveur **IMAP** (réception) et un serveur **SMTP** (envoi). Pour l’envoi des invitations, remplissez dans `.env` les paramètres **SMTP** de votre fournisseur (voir exemples ci‑dessous).

---

## ✅ Ce qui a été configuré

1. **Classe Mailable** : `app/Mail/QuestionnaireInvitation.php`
2. **Template d'email** : `resources/views/emails/questionnaire-invitation.blade.php`
3. **Envoi d'email** dans `QuestionnaireController` (lien + code unique vers le formulaire)

---

## 📧 Configurer l’envoi avec votre boîte mail (SMTP)

Éditez le fichier `api/.env` et renseignez les variables **SMTP** (pas IMAP) de votre fournisseur.

### Gmail

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=votre-email@gmail.com
MAIL_PASSWORD=votre-mot-de-passe-application
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="votre-email@gmail.com"
MAIL_FROM_NAME="Volonté Canada"
```

- Créez un [mot de passe d’application](https://myaccount.google.com/apppasswords) (recommandé) plutôt que votre mot de passe principal.

### Outlook / Microsoft 365

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.office365.com
MAIL_PORT=587
MAIL_USERNAME=votre-email@outlook.com
MAIL_PASSWORD=votre-mot-de-passe
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="votre-email@outlook.com"
MAIL_FROM_NAME="Volonté Canada"
```

### OVH

```env
MAIL_MAILER=smtp
MAIL_HOST=ssl0.ovh.net
MAIL_PORT=587
MAIL_USERNAME=contact@votredomaine.com
MAIL_PASSWORD=votre-mot-de-passe
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="contact@votredomaine.com"
MAIL_FROM_NAME="Volonté Canada"
```

### Autre fournisseur (IMAP + SMTP)

Utilisez les **paramètres SMTP** fournis par votre hébergeur (souvent dans « Paramètres messagerie » ou « Configuration IMAP/SMTP »). En général :
- **Hôte SMTP** : `smtp.domaine.com` ou `mail.domaine.com`
- **Port** : 587 (TLS) ou 465 (SSL)
- Si port 465 : `MAIL_ENCRYPTION=ssl` et `MAIL_PORT=465`

---

## Option 1 (résumé) : Votre propre SMTP

Modifiez le fichier `.env` avec les valeurs SMTP de votre fournisseur (voir exemples ci‑dessus).

### Option 2 : Mailtrap (développement / test)

1. Créez un compte sur [Mailtrap.io](https://mailtrap.io)
2. Configurez dans `.env` :

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=votre-username-mailtrap
MAIL_PASSWORD=votre-password-mailtrap
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="noreply@cabinet-immigration.com"
MAIL_FROM_NAME="Cabinet d'Immigration"
```

### Option 3 : Autres services

- **SendGrid** : Service professionnel avec API
- **Mailgun** : Service fiable pour les emails transactionnels
- **Amazon SES** : Solution cloud scalable

## 🔍 Vérifier les logs

Si le mailer est sur "log", vous pouvez voir les emails dans :
```
storage/logs/laravel.log
```

Recherchez les lignes contenant "Message-ID" pour voir les emails enregistrés.

## 🧪 Tester l'envoi

Après configuration, testez avec :

```bash
php artisan tinker
```

Puis :
```php
use App\Models\QuestionnaireRequest;
use App\Mail\QuestionnaireInvitation;
use Illuminate\Support\Facades\Mail;

$questionnaire = QuestionnaireRequest::first();
Mail::to('test@example.com')->send(new QuestionnaireInvitation($questionnaire));
```

## 📝 Notes importantes

1. **En développement** : Utilisez Mailtrap pour éviter d'envoyer de vrais emails
2. **En production** : Configurez un service SMTP fiable (Gmail, SendGrid, etc.)
3. **Sécurité** : Ne commitez jamais les mots de passe dans `.env` dans Git
4. **Rate limiting** : Certains services (comme Gmail) ont des limites d'envoi

## 🔗 Lien dans l’email (adresse réseau)

Les emails d’invitation contiennent un lien vers le formulaire. Par défaut ce lien pointe vers le frontend Next.js. Pour que le client ouvre le formulaire depuis n’importe quel appareil sur le réseau (et pas seulement localhost), définissez dans `api/.env` :

```env
FRONTEND_URL=http://192.168.2.105:3001
```

Remplacez par l’**adresse réseau** de votre machine (et le port du frontend si différent). Sans slash final. Après modification : `php artisan config:clear`.

---

## 🚀 Après configuration

1. Renseignez les variables SMTP dans `api/.env` (voir exemples ci‑dessus).
2. Videz le cache : `php artisan config:clear`
3. Testez l’envoi (questionnaire « Envoyer » ou test avec Tinker).
4. Vérifiez la réception du mail (lien + code).



