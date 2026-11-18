<?php

return [
    // Clé de chiffrement des sauvegardes (php artisan backup:key). À garder aussi HORS du serveur.
    'encryption_key' => env('BACKUP_ENCRYPTION_KEY'),

    // Chemin de mysqldump (ex. sous XAMPP : C:\xampp\mysql\bin\mysqldump.exe).
    'mysqldump' => env('BACKUP_MYSQLDUMP_PATH', 'mysqldump'),

    // Durée de conservation des sauvegardes, en jours (les 3 dernières sont toujours gardées).
    'keep_days' => (int) env('BACKUP_KEEP_DAYS', 30),

    // Alerte si la dernière sauvegarde a plus de N heures.
    'max_age_hours' => (int) env('BACKUP_MAX_AGE_HOURS', 26),
];
