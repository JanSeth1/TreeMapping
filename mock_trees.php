<?php
$conn = new mysqli('localhost', 'root', '', 'treemap');
$conn->query('DELETE FROM trees');
$conn->query("INSERT INTO trees (type, lat, lng) VALUES
  ('Oak', 9.753, 118.759),
  ('Oak', 9.751, 118.761),
  ('Maple', 9.754, 118.757),
  ('Maple', 9.752, 118.7585),
  ('Pine', 9.7505, 118.7605),
  ('Pine', 9.7535, 118.758)
");
echo 'Mock data inserted';
?>