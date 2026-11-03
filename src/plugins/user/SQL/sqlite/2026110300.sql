CREATE TABLE IF NOT EXISTS user_settings (
  user_id integer NOT NULL PRIMARY KEY,
  display_name varchar(255) NOT NULL default '',
  colors text DEFAULT NULL,
  created datetime NOT NULL default '0000-00-00 00:00:00',
  changed datetime NOT NULL default '0000-00-00 00:00:00'
);
