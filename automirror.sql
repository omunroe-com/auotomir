CREATE TABLE zone_last_dump(
	lastdump timestamp without time zone
);
INSERT INTO zone_last_dump values ('2000-01-01');

CREATE TABLE mirrortypes (
	type varchar(8) NOT NULL PRIMARY KEY,
	masterip varchar(16) NOT NULL UNIQUE,
	masterhost text NOT NULL,
	mirrorhost text NOT NULL,
	syncfile text NOT NULL
);

CREATE TABLE mirrors (
	id SERIAL NOT NULL PRIMARY KEY,
	type varchar(8) NOT NULL REFERENCES mirrortypes(type),
	ip varchar(16) NOT NULL UNIQUE,
	enabled int NOT NULL,
	insync int NOT NULL,
	flapping int NOT NULL,
	description varchar(128) NOT NULL
);

CREATE TABLE mirror_state_change (
	mirror int NOT NULL REFERENCES mirrors(id),
	dat timestamp without time zone NOT NULL,
	newstate int NOT NULL,
	comment varchar(256) NOT NULL,
	CONSTRAINT mirror_state_change_pk PRIMARY KEY (mirror, dat)
);

CREATE TABLE nameservers (
	host varchar(64) NOT NULL PRIMARY KEY,
	ip varchar(16) NOT NULL UNIQUE
);

INSERT INTO mirrortypes VALUES ('static', '217.196.146.204', 'wwwmaster.postgresql.org', 'www.postgresql.org', 'web_sync_timestamp');
INSERT INTO mirrortypes VALUES ('ftp', '204.145.120.228', 'ftp.postgresql.org', 'ftp.postgresql.org', 'pub/sync_timestamp');
INSERT INTO mirrors (type,ip,enabled,insync,flapping) VALUES ('static','212.247.200.180',1,1,0);
INSERT INTO mirrors (type,ip,enabled,insync,flapping) VALUES ('static','65.19.161.2',1,1,0);
INSERT INTO mirrors (type,ip,enabled,insync,flapping) VALUES ('static','66.98.251.159',1,1,0);

INSERT INTO nameservers (host,ip) VALUES ('ns.hub.org','200.46.204.2');
INSERT INTO nameservers (host,ip) VALUES ('ns2.hub.org','66.98.250.36');
