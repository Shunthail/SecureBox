<h1 align="center">SecureBox</h1>

SecureBox est un projet réalisé en binôme lors d’un **hackathon** organisé par **La Briqueterie** et **EPITECH**, que nous avons remporté.

L’application permet de transférer temporairement des fichiers avec une durée de validité personnalisée et une protection facultative par mot de passe.

Les fichiers sont chiffrés sur le serveur et deviennent inaccessibles à leur expiration. L’expéditeur retrouve ses transferts dans un historique conservé localement dans son navigateur.

Le projet utilise PHP et Symfony pour le back-end ainsi que JavaScript pour l’interface. Les métadonnées des transferts sont enregistrées dans une base SQLite.

## Installation

### Prérequis

- Git
- PHP 8.2 ou une version supérieure
- Composer
- Les extensions PHP ctype, fileinfo, mbstring, openssl, pdo et pdo_sqlite

### Récupérer le projet

Depuis le dossier dans lequel tu souhaites installer le projet :

    git clone https://github.com/Shunthail/SecureBox.git
    cd SecureBox

### Installer les dépendances

    composer install

### Préparer la configuration

    composer setup

Cette commande prépare les dossiers de stockage et génère les secrets nécessaires au fonctionnement de l’application. Les clés existantes sont conservées.

Les secrets et les fichiers transférés sont exclus de Git.

### Lancer l’application

    composer start

L’application est ensuite accessible à l’adresse suivante :

http://127.0.0.1:3030

Le serveur local accepte les fichiers jusqu’à 50 Mo. Aucun transfert ni historique n’est préenregistré.

### Supprimer les transferts expirés

Pour supprimer les fichiers expirés et leurs métadonnées :

    php bin/console app:prune

Cette commande peut être planifiée avec cron pour effectuer un nettoyage régulier.

### Vérifications

Pour exécuter les tests et vérifier la configuration Symfony :

    composer test
    composer check

## Le hackathon

Nous avons conçu SecureBox pour proposer un outil de partage simple avec une durée de conservation limitée et des protections intégrées au parcours de transfert.

Le projet a été développé à deux et a remporté le **hackathon** organisé par **La Briqueterie** et **EPITECH**.

[EPITECH](https://www.epitech.eu/programme-grande-ecole-informatique/) est une école privée d’informatique proposant un Programme Grande École en cinq ans. [La Briqueterie](https://www.la-briqueterie.fr/) est un collectif de professionnels du numérique et un espace de coworking à Marseille.

## À propos du projet

J’ai pris en charge l’API Symfony et les mécanismes de protection des transferts. Mon travail portait notamment sur la validation des fichiers reçus, leur chiffrement côté serveur et le contrôle des téléchargements par mot de passe. J’ai également travaillé sur la gestion de l’expiration et la suppression des données associées.

Pour préserver mon anonymat et celui de l’autre participant, j’ai adapté cette version publique en retirant les informations personnelles présentes dans le projet, notamment les noms, les prénoms et les coordonnées.

Ce dépôt accompagne la présentation de mes compétences sur [shunthail.fr](https://shunthail.fr). Il s’adresse notamment aux recruteurs qui souhaitent consulter mon travail et explorer le code sans avoir à me contacter au préalable.

## Réutilisation et contact

Vous pouvez utiliser le code ou vous inspirer du projet sans me demander d’autorisation. Un message reste toujours apprécié si ce travail vous aide à lancer un projet ou à développer une idée.

Si vous avez une question ou besoin d’aide, vous pouvez me contacter sur Discord : **shunthail**.
