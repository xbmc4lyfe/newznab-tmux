
CREATE TABLE `predb` (
  `id` int(10) NOT NULL auto_increment,
  `section` varchar(20) NOT NULL default '',
  `rlsname` varchar(255) NOT NULL default '',
  `grp` varchar(50) NOT NULL default '',
  `status` decimal(1,0) NOT NULL default '0',
  `nukereason` varchar(255) default NULL,
  `ctime` decimal(10,0) NOT NULL default '0',
  `size` int(8) default NULL,
  `files` int(6) default NULL,
  PRIMARY KEY  (`id`),
  UNIQUE KEY `rlsname` (`rlsname`),
  KEY `grp` (`grp`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

/*!40000 ALTER TABLE `predb` DISABLE KEYS */;
LOCK TABLES `predb` WRITE;
INSERT INTO `predb` VALUES (2694710,'HDTV','ER.S12E13.iTALiAN.LD.HDTV.XviD-TRiBECA','TRiBECA','0',NULL,'1183557532',NULL,NULL),(2694713,'XVID','Mean.Streets.SE.1973.DVDRip.XviD.Hungarian-GTB','GTB','0',NULL,'1183557625',696,49),(2694721,'MP3','Alcatraz-Give_Me_Love-(CATRAZ001)-Bootleg-Vinyl-2007-MPX','MPX','0',NULL,'1183558000',21,2);
INSERT INTO `predb` VALUES (2694739,'0DAY','Zards.Software.Cleanse.Uninstaller.v3.0.1.0-HERiTAGE','HERiTAGE','0',NULL,'1183558848',NULL,NULL),(2696343,'GAMES','Schach.Und.Matt.2-SiLENTGATE','SiLENTGATE','0',NULL,'1183653356',529,36),(2696479,'APPS','Harry.Potter.en.de.Orde.van.de.Feniks.DUTCH-VLiSO','VLiSO','0',NULL,'1183660816',3560,75);
UNLOCK TABLES;
/*!40000 ALTER TABLE `predb` ENABLE KEYS */;
