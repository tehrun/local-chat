# Domain Boundaries

- `Message/`: private and group message business rules.
- `Group/`: group lifecycle and membership rules.
- `Friendship/`: friend request, block, and relationship rules.
- `Presence/`: online status and typing/presence lifecycle.

These modules are the target for migrating business logic out of `src/bootstrap.php`.
