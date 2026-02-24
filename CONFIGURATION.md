# Configuration de l'API Laravel

## ✅ Configuration terminée

### Base de données

- **Type**: MySQL
- **Configuration**: `.env` configuré avec `DB_CONNECTION=mysql`
- **Host**: 127.0.0.1
- **Port**: 3307
- **Database**: volont

### Migrations exécutées

Toutes les migrations ont été exécutées avec succès :

1. ✅ `0001_01_01_000000_create_users_table` - Table des utilisateurs
2. ✅ `0001_01_01_000001_create_cache_table` - Table de cache
3. ✅ `0001_01_01_000002_create_jobs_table` - Table des jobs
4. ✅ `2019_12_14_000001_create_personal_access_tokens_table` - Tokens Sanctum
5. ✅ `2024_12_05_000001_create_admins_table` - Table des administrateurs
6. ✅ `2024_12_05_000002_create_clients_table` - Table des clients
7. ✅ `2024_12_10_000001_create_questionnaire_requests_table` - Table des questionnaires

### Seeders exécutés

- ✅ `AdminSeeder` - Création des administrateurs par défaut

### Routes API configurées

#### Routes Admin (protégées)
- `POST /api/admin/questionnaires/send` - Envoyer un formulaire
- `GET /api/admin/questionnaires` - Lister les questionnaires
- `GET /api/admin/questionnaires/{id}` - Détails d'un questionnaire
- `GET /api/admin/clients` - Liste des clients

#### Routes Publiques
- `POST /api/questionnaires/verify` - Vérifier l'accès (email + code)
- `POST /api/questionnaires/{code}/save` - Sauvegarder les données
- `POST /api/questionnaires/{code}/submit` - Soumettre le formulaire

## Commandes utiles

### Migrations
```bash
# Voir le statut des migrations
php artisan migrate:status

# Exécuter les migrations
php artisan migrate

# Réinitialiser et réexécuter les migrations avec seeders
php artisan migrate:fresh --seed

# Annuler la dernière migration
php artisan migrate:rollback
```

### Cache
```bash
# Vider le cache de configuration
php artisan config:clear

# Vider le cache de routes
php artisan route:clear

# Vider tous les caches
php artisan optimize:clear
```

### Routes
```bash
# Lister toutes les routes
php artisan route:list

# Lister les routes de questionnaires
php artisan route:list --path=questionnaire
```

### Serveur
```bash
# Démarrer le serveur de développement (accessible en local uniquement)
php artisan serve

# Démarrer sur un port spécifique
php artisan serve --port=8000

# Accès RÉSEAU : écouter sur toutes les interfaces (pour accéder via l'IP, ex. 192.168.2.105:8000)
php artisan serve --host=0.0.0.0 --port=8000
```

**Accès depuis le réseau** : Quand vous ouvrez le frontend via l’adresse réseau (ex. http://192.168.2.105:3001), le frontend appelle l’API sur la **même machine** (ex. 192.168.2.105:8000). Il faut donc démarrer l’API avec `--host=0.0.0.0` pour qu’elle accepte les connexions depuis d’autres appareils.

## Structure de la base de données

### Table `questionnaire_requests`

| Colonne | Type | Description |
|---------|------|-------------|
| id | bigint | ID unique |
| unique_code | string(32) | Code unique pour accéder au formulaire |
| client_type | enum | 'existing' ou 'custom' |
| client_id | bigint (nullable) | ID du client si existant |
| custom_name | string (nullable) | Nom si client personnalisé |
| email | string | Email du destinataire |
| phone | string (nullable) | Téléphone si client personnalisé |
| form_type | string | Type de formulaire |
| status | enum | 'pending', 'in_progress', 'completed', 'expired' |
| form_data | json (nullable) | Données du formulaire |
| sent_at | timestamp (nullable) | Date d'envoi |
| expires_at | timestamp | Date d'expiration (14 jours) |
| completed_at | timestamp (nullable) | Date de complétion |
| sent_by | bigint (nullable) | ID de l'admin qui a envoyé |
| created_at | timestamp | Date de création |
| updated_at | timestamp | Date de mise à jour |

## Prochaines étapes

1. ✅ Base de données configurée
2. ✅ Migrations exécutées
3. ✅ Routes configurées
4. ⏳ Configuration de l'envoi d'emails (optionnel)
5. ⏳ Tests de l'API

## Notes

- La base de données SQLite est créée automatiquement dans `database/database.sqlite`
- Les migrations sont automatiquement exécutées lors du déploiement
- Les seeders créent les administrateurs par défaut pour les tests

