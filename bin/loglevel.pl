#!/usr/bin/perl
# Unwetter4Lox – LoxBerry Log-Session Bridge
# Erstellt eine neue LoxBerry Log-Session via LoxBerry::Log und gibt zurück:
#   Zeile 1: Pfad zur Log-Datei
#   Zeile 2: aktueller Log-Level (0-7)
# Aufgerufen vom Python-Daemon beim Start.
# REPLACELBPPLUGINDIR wird vom LoxBerry-Installer durch den Plugin-Namen ersetzt.

use strict;
use warnings;

use LoxBerry::Log;
use LoxBerry::System;

my $log = LoxBerry::Log->new(
    name    => 'Daemon',
    package => 'REPLACELBPPLUGINDIR',
    stderr  => 0,
    addtime => 0,
);

# Dateiname ermitteln (dokumentierte Methode + Fallback für ältere LoxBerry-Versionen)
my $filename = eval { $log->filename() } // $log->{filename};

if (!$filename) {
    print STDERR "loglevel.pl: Log-Datei konnte nicht ermittelt werden\n";
    exit 1;
}

# Log-Level ermitteln (wird in der LoxBerry-Oberfläche eingestellt)
my $loglevel = eval { LoxBerry::System::loglevel() } // '';
$loglevel = 6 unless defined $loglevel && $loglevel =~ /^\d+$/;

print "$filename\n";
print "$loglevel\n";
exit 0;
