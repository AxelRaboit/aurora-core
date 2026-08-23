---
name: Rôle d'aurora-client - projet démo et template de départ
description: Aurora-client est un projet de démonstration qui illustre toutes les possibilités d'aurora-core, et sert de base pour démarrer de nouveaux projets clients
type: project
---

Aurora-client est le **projet de démonstration de référence** d'aurora-core. Il illustre concrètement toutes les façons d'étendre Aurora :
- Extension d'entité existante (Agency + champ code - 5 couches complètes)
- Module client from scratch (Tracking - module de suivi de projets)
- Override de composant Vue (AgenciesApp avec slot extra-form-fields)
- Frontend public module (TrackingFrontend)

**Why:** Sert à la fois de showcase pour présenter les capacités d'Aurora, et de point de départ ("template") pour créer un nouveau projet client. Un nouveau projet se clone depuis aurora-client et adapte le contenu existant à son domaine.

**How to apply:** Quand on développe aurora-client, penser "démo + template" : chaque feature ajoutée doit être représentative d'un pattern réutilisable, pas d'un besoin métier spécifique. Le module Tracking reste simple exprès - il montre le pattern, pas une vraie app de gestion de projet.
