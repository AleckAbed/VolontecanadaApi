# 🔐 Configuration de l'Authentification - Cabinet d'Immigration

## ✅ Ce qui a été configuré

### 1. Modèles créés
- **Admin** (`app/Models/Admin.php`) - Pour les administrateurs du cabinet
- **Client** (`app/Models/Client.php`) - Pour les clients

### 2. Tables de base de données
- **admins** - Stocke les administrateurs (avec rôles: super_admin, admin, manager, agent)
- **clients** - Stocke les clients avec leurs informations personnelles

### 3. Authentification
- **Laravel Sanctum** - Gestion des tokens API
- **Multi-guards** - Séparation Admin/Client
- **Routes API** configurées dans `routes/api.php`

### 4. Controllers
- **AdminAuthController** - Login/Logout/Me pour admins
- **ClientAuthController** - Register/Login/Logout/Me pour clients

### 5. CORS
- Configuré pour accepter les requêtes depuis `http://localhost:3001` (frontend Next.js)

## 🚀 Comment lancer le backend

### Étape 1 : Exécuter les migrations

```bash
cd d:\volont\api
php artisan migrate
```

### Étape 2 : Créer les données de test (Seeder)

```bash
php artisan db:seed
```

Cela créera deux administrateurs :
- **Super Admin**: admin@cabinet-immigration.com / password
- **Admin Test**: test@cabinet-immigration.com / password

### Étape 3 : Lancer le serveur Laravel

```bash
php artisan serve
```

Le serveur démarrera sur `http://localhost:8000`

## 📡 Endpoints API disponibles

### Admin Routes (Préfixe: `/api/admin`)

| Méthode | Endpoint | Description | Auth Required |
|---------|----------|-------------|---------------|
| POST | `/api/admin/login` | Connexion admin | ❌ |
| POST | `/api/admin/logout` | Déconnexion admin | ✅ |
| GET | `/api/admin/me` | Infos admin connecté | ✅ |

**Exemple de login admin:**
```bash
POST http://localhost:8000/api/admin/login
Content-Type: application/json

{
  "email": "admin@cabinet-immigration.com",
  "password": "password"
}
```

**Réponse:**
```json
{
  "success": true,
  "message": "Connexion réussie",
  "data": {
    "admin": {
      "id": 1,
      "name": "Super Admin",
      "email": "admin@cabinet-immigration.com",
      "role": "super_admin"
    },
    "token": "1|laravel_sanctum_token_here"
  }
}
```

### Client Routes (Préfixe: `/api/client`)

| Méthode | Endpoint | Description | Auth Required |
|---------|----------|-------------|---------------|
| POST | `/api/client/register` | Inscription client | ❌ |
| POST | `/api/client/login` | Connexion client | ❌ |
| POST | `/api/client/logout` | Déconnexion client | ✅ |
| GET | `/api/client/me` | Infos client connecté | ✅ |

**Exemple d'inscription client:**
```bash
POST http://localhost:8000/api/client/register
Content-Type: application/json

{
  "first_name": "Jean",
  "last_name": "Dupont",
  "email": "jean.dupont@example.com",
  "password": "password123",
  "password_confirmation": "password123",
  "phone": "+33612345678"
}
```

### Routes protégées

Pour accéder aux routes protégées, incluez le token dans le header :

```bash
Authorization: Bearer YOUR_TOKEN_HERE
```

**Exemple:**
```bash
GET http://localhost:8000/api/admin/me
Authorization: Bearer 1|laravel_sanctum_token_here
```

## 🧪 Tester l'API

### Option 1 : Avec curl

```bash
# Test de connexion admin
curl -X POST http://localhost:8000/api/admin/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@cabinet-immigration.com","password":"password"}'
```

### Option 2 : Avec Postman ou Insomnia
1. Importer la collection d'endpoints ci-dessus
2. Tester chaque route

### Option 3 : Via le frontend Next.js
Une fois le frontend adapté, vous pourrez tester l'authentification complète.

## 📋 Structure de la base de données

### Table `admins`
```
- id
- name
- email (unique)
- email_verified_at
- password
- role (super_admin|admin|manager|agent)
- is_active (boolean)
- remember_token
- timestamps
```

### Table `clients`
```
- id
- first_name
- last_name
- email (unique)
- email_verified_at
- password
- phone
- date_of_birth
- nationality
- passport_number (unique)
- address
- is_active (boolean)
- remember_token
- timestamps
```

## 🔒 Sécurité

- Les mots de passe sont hashés avec bcrypt
- Les tokens sont gérés par Laravel Sanctum
- CORS configuré pour `localhost:3001` uniquement
- Guards séparés pour Admin et Client

## 📝 Prochaines étapes

1. ✅ Backend configuré
2. ⏳ Adapter le frontend Next.js
3. ⏳ Créer les pages d'authentification
4. ⏳ Gérer les états d'authentification côté client
5. ⏳ Implémenter les fonctionnalités métier


