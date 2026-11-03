CREATE TABLE user_settings (
    user_id integer NOT NULL PRIMARY KEY,
    display_name varchar(255) NOT NULL DEFAULT '',
    colors text,
    created timestamp with time zone NOT NULL DEFAULT now(),
    changed timestamp with time zone NOT NULL DEFAULT now()
);

INSERT INTO "system" (name, value) VALUES ('user-version', '2026110300');
