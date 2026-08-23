# Process & Méthode

- [process_make_ft_before_commit.md](process_make_ft_before_commit.md) - **toujours** `make ft` avant chaque commit
- [process_doc_audit_before_commit.md](process_doc_audit_before_commit.md) - **toujours** auditer les docs/mémoires qui parlent du sujet touché, dans le même commit (vaut code↔doc)
- [process_audit_ged_lessons.md](process_audit_ged_lessons.md) - checklist d'audit post-module : hooks manager, interfaces serializer, TimestampableTrait, fetch bruts
- [process_audit_before_generalize.md](process_audit_before_generalize.md) - auditer avant de généraliser une convention sur N entités
- [process_atomic_commits.md](process_atomic_commits.md) - un commit par entité lors des rollouts massifs
- [process_release.md](process_release.md) - processus de release : CHANGELOG, tag, communication vers aurora-client et projets clients
- [process_propagate_aurora_updates.md](process_propagate_aurora_updates.md) - après un changement core sur develop : push + `make aurora-update` sur les consommateurs (aurora-client = modèle, à bumper en premier)
- [pitfall_empty_migration_registered.md](pitfall_empty_migration_registered.md) - écrire `up()` AVANT le premier `migrations:migrate`, sinon le template vide est marqué exécuté et il faut une migration de rattrapage
