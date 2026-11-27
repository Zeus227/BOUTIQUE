-- ============================================================
--  Comptes utilisateurs — à importer APRÈS schema.sql
--  Les mots de passe sont stockés sous forme de hachage SHA-256
--  (les mêmes identifiants et mots de passe qu'avant continuent de fonctionner).
-- ============================================================
INSERT INTO `users` (`username`, `display_name`, `password_hash`, `role`) VALUES
  ('ousseini', 'Ousseini', '054e3b308708370ea029dc2ebd1646c498d59d7203c9e1a44cf0484df98e581a', 'admin'),
  ('bachir', 'Bachir', '74526f16c984df9b057949b19bcf3c7cd1050453d5d1069a736e753fdd4df447', 'admin'),
  ('sadik', 'Sadik', 'ab9c385e80791bca43787f9e53c029a713458265fc21c3a02be63dd545f2dbd8', 'vendeur');
