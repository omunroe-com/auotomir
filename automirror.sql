CREATE SCHEMA automirror;
SET search_path='automirror';
CREATE TABLE zone_last_dump(
	lastdump timestamp without time zone
);
INSERT INTO zone_last_dump values ('2000-01-01');

CREATE TABLE mirrortypes (
	type varchar(8) NOT NULL PRIMARY KEY
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

INSERT INTO mirrortypes (type) VALUES ('static');
INSERT INTO mirrors (type,ip,enabled,insync,flapping) VALUES ('static','212.247.200.180',1,1,0,'Eastside');
INSERT INTO mirrors (type,ip,enabled,insync,flapping) VALUES ('static','65.19.161.2',1,1,0,'Borg');
INSERT INTO mirrors (type,ip,enabled,insync,flapping) VALUES ('static','66.98.251.159',1,1,0,'svr4');
INSERT INTO mirrors (type,ip,enabled,insync,flapping) VALUES ('static','217.20.119.91',1,1,0,'Pervasive');

INSERT INTO nameservers (host,ip) VALUES ('ns.hub.org','200.46.204.2');
INSERT INTO nameservers (host,ip) VALUES ('ns2.hub.org','66.98.250.36');
INSERT INTO nameservers (host,ip) VALUES ('ns3.hub.org','200.46.204.4');
INSERT INTO nameservers (host,ip) VALUES ('ns-a.lerctr.org','192.147.25.11');
INSERT INTO nameservers (host,ip) VALUES ('ns-b.lerctr.org','192.147.25.45');
INSERT INTO nameservers (host,ip) VALUES ('ns-1.sollentuna.net','62.65.68.8');

