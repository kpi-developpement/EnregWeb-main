# EnregWeb — Application de lecture depuis NAS

Cette version lit les enregistrements directement depuis le NAS monté sur le serveur.

## Points corrigés

- L'application ne dépend plus de `audio_index` pour afficher les audios. Elle scanne le dossier NAS réel par date.
- Le tag seul suffit pour chercher. Le 2e numéro est optionnel.
- Le lecteur utilise `download.php` pour streamer l'audio avec session + signature.
- Le téléchargement reste contrôlé par le droit `can_download`.
- Le streaming est autorisé aux utilisateurs connectés, même si le téléchargement est interdit.
- Les chemins absolus `/mnt/nas_enrg/...` ne sont plus envoyés au navigateur.

## Montage NAS attendu

Le NAS doit être monté côté serveur:

```bash
sudo mount -t cifs //172.16.85.2/Enregistrement /mnt/nas_enrg \
-o credentials=/root/.nascredentials,uid=webserver,gid=webserver,iocharset=utf8,vers=3.0
```

Le dossier attendu est:

```text
/mnt/nas_enrg/audio_mails/YYYY-MM-DD/*.mp3
```

## Docker

Le conteneur monte le NAS en lecture seule:

```yaml
volumes:
  - /mnt/nas_enrg/audio_mails:/var/www/html/audio_mails:ro
```

Lancer:

```bash
docker compose up -d --build
```

Si Docker Hub/DNS est en panne mais que l'image existe déjà:

```bash
docker compose up -d --no-build
```

## Diagnostic

Ouvrir:

```text
/health.php
```

Il doit afficher le contenu du dossier audio monté.

Dans Chrome, si un audio ne se lit pas:

```text
F12 → Network → download.php
```

- `200` ou `206`: OK
- `401`: session non connectée
- `403`: signature invalide ou droit téléchargement
- `404`: fichier absent du NAS
