=head1 NAME

 Servers::httpd::apache_itk::installer - i-MSCP Apache2/ITK Server implementation

=cut

# i-MSCP - internet Multi Server Control Panel
# Copyright (C) 2010-2017 by Laurent Declercq <l.declercq@nuxwin.com>
#
# This program is free software; you can redistribute it and/or
# modify it under the terms of the GNU General Public License
# as published by the Free Software Foundation; either version 2
# of the License, or (at your option) any later version.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with this program; if not, write to the Free Software
# Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.

package Servers::httpd::apache_itk::installer;

use strict;
use warnings;
use File::Basename;
use iMSCP::Config;
use iMSCP::Crypt qw/ randomStr /;
use iMSCP::Database;
use iMSCP::Debug;
use iMSCP::Dir;
use iMSCP::EventManager;
use iMSCP::Execute;
use iMSCP::File;
use iMSCP::Getopt;
use iMSCP::ProgramFinder;
use iMSCP::Service;
use iMSCP::SystemGroup;
use iMSCP::SystemUser;
use iMSCP::TemplateParser;
use Servers::httpd::apache_itk;
use Servers::sqld;
use version;
use parent 'Common::SingletonClass';

=head1 DESCRIPTION

 Installer for the i-MSCP Apache2/ITK Server implementation.

=head1 PUBLIC METHODS

=over 4

=item registerSetupListeners(\%eventManager)

 Register setup event listeners

 Param iMSCP::EventManager \%eventManager
 Return int 0 on success, other on failure

=cut

sub registerSetupListeners
{
    my ($self, $eventManager) = @_;

    $eventManager->register(
        'beforeSetupDialog',
        sub {
            push @{$_[0]}, sub { $self->showDialog( @_ ) };
            0;
        }
    );
}

=item showDialog(\%dialog)

 Show dialog

 Param iMSCP::Dialog \%dialog
 Return int 0 on success, other on failure

=cut

sub showDialog
{
    my ($self, $dialog) = @_;

    my $confLevel = main::setupGetQuestion( 'PHP_CONFIG_LEVEL', $self->{'phpConfig'}->{'PHP_CONFIG_LEVEL'} );

    if ($main::reconfigure =~ /^(?:httpd|php|servers|all|forced)$/ || $confLevel !~ /^per_(?:site|domain|user)$/) {
        $confLevel =~ s/_/ /;
        (my $rs, $confLevel) = $dialog->radiolist(
            <<"EOF", [ 'per_site', 'per_domain', 'per_user' ], $confLevel =~ /^per (?:user|domain)$/ ? $confLevel : 'per site' );

\\Z4\\Zb\\ZuPHP configuration level\\Zn

Please choose the PHP configuration level you want use. Available levels are:

\\Z4Per user:\\Zn Changes made through the PHP Editor apply to all domains
\\Z4Per domain:\\Zn Changes made through the PHP editor apply to selected domain, including its subdomains
\\Z4Per site:\\Zn Change made through the PHP editor apply to selected domain only
EOF
        return $rs if $rs >= 30;
    }

    ($self->{'phpConfig'}->{'PHP_CONFIG_LEVEL'} = $confLevel) =~ s/ /_/;
    0;
}

=item install()

 Process install tasks

 Return int 0 on success, other on failure

=cut

sub install
{
    my $self = shift;

    my $rs = $self->_setApacheVersion();
    $rs ||= $self->_makeDirs();
    $rs ||= $self->_copyDomainDisablePages;
    $rs ||= $self->_buildPhpConfFiles();
    $rs ||= $self->_buildApacheConfFiles();
    $rs ||= $self->_installLogrotate();
    $rs ||= $self->_setupVlogger();
    $rs ||= $self->_saveConf();
    $rs ||= $self->_cleanup();
}

=back

=head1 PRIVATE METHODS

=over 4

=item _init()

 Initialize instance

 Return Servers::httpd::apache_itk::installer

=cut

sub _init
{
    my $self = shift;

    $self->{'eventManager'} = iMSCP::EventManager->getInstance();
    $self->{'httpd'} = Servers::httpd::apache_itk->getInstance();
    $self->{'apacheCfgDir'} = $self->{'httpd'}->{'apacheCfgDir'};
    $self->{'config'} = $self->{'httpd'}->{'config'};

    # Be sure to work with newest conffile
    # Cover case where the conffile has been loaded prior installation of new files (even if discouraged)
    untie(%{$self->{'config'}});
    tie %{$self->{'config'}}, 'iMSCP::Config', fileName => "$self->{'apacheCfgDir'}/apache.data";

    my $oldConf = "$self->{'apacheCfgDir'}/apache.old.data";
    if(-f $oldConf) {
        tie my %oldConfig, 'iMSCP::Config', fileName => $oldConf, readonly => 1;
        while(my($key, $value) = each(%oldConfig)) {
            next unless exists $self->{'config'}->{$key};
            $self->{'config'}->{$key} = $value;
        }
    }

    $self->{'phpCfgDir'} = $self->{'httpd'}->{'phpCfgDir'};
    $self->{'phpConfig'} = $self->{'httpd'}->{'phpConfig'};

    # Be sure to work with newest conffile
    # Cover case where the conffile has been loaded prior installation of new files (even if discouraged)
    untie(%{$self->{'phpConfig'}});
    tie %{$self->{'phpConfig'}}, 'iMSCP::Config', fileName => "$self->{'phpCfgDir'}/php.data";

    $oldConf = "$self->{'phpCfgDir'}/php.old.data";
    if(-f $oldConf) {
        tie my %oldConfig, 'iMSCP::Config', fileName => $oldConf, readonly => 1;
        while(my($key, $value) = each(%oldConfig)) {
            next unless exists $self->{'phpConfig'}->{$key};
            $self->{'phpConfig'}->{$key} = $value;
        }
    }

    $self->_guessPhpVariables();
    $self;
}

=item _guessPhpVariables

 Guess PHP Variables

 Return int 0 on success, die on failure

=cut

sub _guessPhpVariables
{
    my $self = shift;

    my ($phpVersion) = $main::imscpConfig{'PHP_SERVER'} =~ /([\d.]+)$/ or die(
        sprintf( "Couldn't guess value for the `%s' PHP configuration parameter.", 'PHP_VERSION' )
    );

    my $rs = execute( "php -d date.timezone=utc -i | grep '(php.ini) Path'", \my $stdout, \my $stderr );
    debug( $stdout ) if $stdout;
    die( $stderr || 'Unknown error' ) if $rs;

    chomp( $stdout );
    my ($phpConfDir) = $stdout =~ /\s([^\s]+)$/;
    defined $phpConfDir or die( "Couldn't guess PHP configuration directory path." );
    my $phpConfBaseDir = dirname($phpConfDir);

    if ($phpConfBaseDir =~ m%php/\Q$phpVersion\E$%) {
        $self->{'phpConfig'}->{'PHP_VERSION'} = $phpVersion;
        $self->{'phpConfig'}->{'PHP_CONF_DIR_PATH'} = $phpConfBaseDir;
        $self->{'phpConfig'}->{'PHP_FPM_POOL_DIR_PATH'} = "$phpConfBaseDir/fpm/pool.d";
        $self->{'phpConfig'}->{'PHP_CLI_BIN_PATH'} = iMSCP::ProgramFinder::find( "php$phpVersion" ) || '';
        $self->{'phpConfig'}->{'PHP_FCGI_BIN_PATH'} = iMSCP::ProgramFinder::find( "php-cgi$phpVersion" ) || '';
        $self->{'phpConfig'}->{'PHP_FPM_BIN_PATH'} = iMSCP::ProgramFinder::find( "php-fpm$phpVersion" ) || '';
    } else {
        $self->{'phpConfig'}->{'PHP_VERSION'} = 5;
        $self->{'phpConfig'}->{'PHP_CONF_DIR_PATH'} = $phpConfBaseDir;
        $self->{'phpConfig'}->{'PHP_FPM_POOL_DIR_PATH'} = "$phpConfBaseDir/fpm/pool.d";
        $self->{'phpConfig'}->{'PHP_CLI_BIN_PATH'} = iMSCP::ProgramFinder::find( 'php5' ) || '';
        $self->{'phpConfig'}->{'PHP_FCGI_BIN_PATH'} = iMSCP::ProgramFinder::find( 'php5-cgi' ) || '';
        $self->{'phpConfig'}->{'PHP_FPM_BIN_PATH'} = iMSCP::ProgramFinder::find( 'php5-fpm' ) || '';
    }

    for(qw/ PHP_CONF_DIR_PATH PHP_FPM_POOL_DIR_PATH /) {
        unless (-d $self->{'phpConfig'}->{$_}) {
            $self->{'phpConfig'}->{$_} = '';
            die(sprintf("Couldn't guess value for the `%s' PHP configuration parameter: directory doesn't exists.", $_));
        }
    }

    for(qw/ PHP_CLI_BIN_PATH PHP_FCGI_BIN_PATH PHP_FPM_BIN_PATH /) {
        next unless $self->{'phpConfig'}->{$_} eq '';
        die( sprintf( "Couldn' guess value for the `%s' PHP configuration parameter.", $_ ) );
    }

    0;
}

=item _setApacheVersion

 Set Apache version

 Return int 0 on success, other on failure

=cut

sub _setApacheVersion
{
    my $self = shift;

    my $rs = execute( 'apache2ctl -v', \ my $stdout, \ my $stderr );
    debug( $stdout ) if $stdout;
    error( $stderr || 'Unknown error' ) if $rs;
    return $rs if $rs;

    if ($stdout !~ m%Apache/([\d.]+)%) {
        error( 'Could not find Apache version from `apache2ctl -v` command output.' );
        return 1;
    }

    $self->{'config'}->{'HTTPD_VERSION'} = $1;
    debug( sprintf( 'Apache version set to: %s', $1 ) );
    0;
}

=item _makeDirs()

 Create directories

 Return int 0 on success, other on failure

=cut

sub _makeDirs
{
    my $self = shift;

    my $rs = $self->{'eventManager'}->trigger( 'beforeHttpdMakeDirs' );
    return $rs if $rs;

    for (
        [
            $self->{'config'}->{'HTTPD_LOG_DIR'},
            $main::imscpConfig{'ROOT_USER'},
            $main::imscpConfig{'ADM_GROUP'},
            0750
        ],
        [
            "$self->{'config'}->{'HTTPD_LOG_DIR'}/" . main::setupGetQuestion( 'BASE_SERVER_VHOST' ),
            $main::imscpConfig{'ROOT_USER'},
            $main::imscpConfig{'ROOT_GROUP'},
            0750
        ]
    ) {
        $rs = iMSCP::Dir->new( dirname => $_->[0] )->make( { user => $_->[1], group => $_->[2], mode => $_->[3] } );
        return $rs if $rs;
    }

    $self->{'eventManager'}->trigger( 'afterHttpdMakeDirs' );
}

=item _copyDomainDisablePages()

 Copy pages for disabled domains

 Return int 0 on success, other on failure

=cut

sub _copyDomainDisablePages
{
    iMSCP::Dir->new( dirname => "$main::imscpConfig{'CONF_DIR'}/skel/domain_disabled_pages" )->rcopy(
        "$main::imscpConfig{'USER_WEB_DIR'}/domain_disabled_pages"
    );
}

=item _buildPhpConfFiles()

 Build PHP configuration files

 Return int 0 on success, other on failure

=cut

sub _buildPhpConfFiles
{
    my $self = shift;

    my $rs = $self->{'eventManager'}->trigger( 'beforeHttpdBuildPhpConfFiles' );
    return $rs if $rs;

    $self->{'httpd'}->setData(
        {
            PEAR_DIR                    => $self->{'phpConfig'}->{'PHP_PEAR_DIR'},
            TIMEZONE                    => main::setupGetQuestion( 'TIMEZONE' ),
            PHP_OPCODE_CACHE_ENABLED    => $self->{'phpConfig'}->{'PHP_OPCODE_CACHE_ENABLED'},
            PHP_OPCODE_CACHE_MAX_MEMORY => $self->{'phpConfig'}->{'PHP_OPCODE_CACHE_MAX_MEMORY'}
        }
    );

    $rs = $self->{'httpd'}->buildConfFile(
        "$self->{'phpCfgDir'}/apache/php.ini",
        { },
        { destination => "$self->{'phpConfig'}->{'PHP_CONF_DIR_PATH'}/apache2/php.ini" }
    );
    $rs = $self->{'httpd'}->disableModules(
        'actions', 'fastcgi', 'fcgid', 'fastcgi_imscp', 'fcgid_imscp', 'php_fpm_imscp', 'suexec', 'php5', 'php5_cgi',
        'php5filter', 'php5.6', 'php7.0', 'php7.1', 'proxy_fcgi', 'proxy_handler', 'mpm_itk', 'mpm_event',
        'mpm_prefork', 'mpm_worker'
    );
    $rs ||= $self->{'httpd'}->enableModules(
        'authz_groupfile', $main::imscpConfig{'PHP_SERVER'}, 'mpm_itk', 'version'
    );
    $rs ||= $self->{'eventManager'}->trigger( 'afterHttpdBuildPhpConfFiles' );
}

=item _buildApacheConfFiles()

 Build Apache configuration files

 Return int 0 on success, other on failure

=cut

sub _buildApacheConfFiles
{
    my $self = shift;

    my $rs = $self->{'eventManager'}->trigger( 'beforeHttpdBuildApacheConfFiles' );
    return $rs if $rs;

    if (-f "$self->{'config'}->{'HTTPD_CONF_DIR'}/ports.conf") {
        $rs = $self->{'eventManager'}->trigger( 'onLoadTemplate', 'apache_itk', 'ports.conf', \ my $cfgTpl, { } );
        return $rs if $rs;

        unless (defined $cfgTpl) {
            $cfgTpl = iMSCP::File->new( filename => "$self->{'config'}->{'HTTPD_CONF_DIR'}/ports.conf" )->get();
            unless (defined $cfgTpl) {
                error( sprintf( 'Could not read %s file', "$self->{'config'}->{'HTTPD_CONF_DIR'}/ports.conf" ) );
                return 1;
            }
        }

        $rs = $self->{'eventManager'}->trigger( 'beforeHttpdBuildConfFile', \$cfgTpl, 'ports.conf' );
        return $rs if $rs;

        $cfgTpl =~ s/^NameVirtualHost[^\n]+\n//gim;

        $rs = $self->{'eventManager'}->trigger( 'afterHttpdBuildConfFile', \$cfgTpl, 'ports.conf' );
        return $rs if $rs;

        my $file = iMSCP::File->new( filename => "$self->{'config'}->{'HTTPD_CONF_DIR'}/ports.conf" );
        $rs = $file->set( $cfgTpl );
        $rs ||= $file->mode( 0644 );
        $rs ||= $file->save();
        return $rs if $rs;
    }

    # Turn off default access log provided by Debian package
    if (-d "$self->{'config'}->{'HTTPD_CONF_DIR'}/conf-available") {
        $rs = $self->{'httpd'}->disableConfs( 'other-vhosts-access-log.conf' );
        return $rs if $rs;
    } elsif (-f "$self->{'config'}->{'HTTPD_CONF_DIR'}/conf.d/other-vhosts-access-log") {
        $rs = iMSCP::File->new(
            filename => "$self->{'config'}->{'HTTPD_CONF_DIR'}/conf.d/other-vhosts-access-log"
        )->delFile();
        return $rs if $rs;
    }

    # Remove default access log file provided by Debian package
    if (-f "$self->{'config'}->{'HTTPD_LOG_DIR'}/other_vhosts_access.log") {
        $rs = iMSCP::File->new( filename => "$self->{'config'}->{'HTTPD_LOG_DIR'}/other_vhosts_access.log" )->delFile();
        return $rs if $rs;
    }

    $self->{'httpd'}->setData(
        {
            HTTPD_CUSTOM_SITES_DIR => $self->{'config'}->{'HTTPD_CUSTOM_SITES_DIR'},
            HTTPD_LOG_DIR          => $self->{'config'}->{'HTTPD_LOG_DIR'},
            HTTPD_ROOT_DIR         => $self->{'config'}->{'HTTPD_ROOT_DIR'},
            VLOGGER_CONF           => "$self->{'apacheCfgDir'}/vlogger.conf"
        }
    );
    $rs ||= $self->{'httpd'}->buildConfFile( '00_nameserver.conf', );
    $rs ||= $self->{'httpd'}->buildConfFile(
        '00_imscp.conf',
        { },
        {
            destination => -d "$self->{'config'}->{'HTTPD_CONF_DIR'}/conf-available"
                ? "$self->{'config'}->{'HTTPD_CONF_DIR'}/conf-available/00_imscp.conf"
                : "$self->{'config'}->{'HTTPD_CONF_DIR'}/conf.d/00_imscp.conf"
        }
    );
    $rs ||= $self->{'httpd'}->enableModules( 'cgid', 'headers', 'proxy', 'proxy_http', 'rewrite', 'setenvif', 'ssl' );
    $rs ||= $self->{'httpd'}->enableSites( '00_nameserver.conf' );
    $rs ||= $self->{'httpd'}->enableConfs( '00_imscp.conf' );
    $rs ||= $self->{'httpd'}->disableSites( 'default', 'default-ssl', '000-default.conf', 'default-ssl.conf' );
    $rs ||= $self->{'eventManager'}->trigger( 'afterHttpdBuildApacheConfFiles' );
}

=item _installLogrotate()

 Install Apache logrotate file

 Return int 0 on success, other on failure

=cut

sub _installLogrotate
{
    my $self = shift;

    my $rs = $self->{'eventManager'}->trigger( 'beforeHttpdInstallLogrotate', 'apache2' );

    $self->{'httpd'}->setData(
        {
            ROOT_USER     => $main::imscpConfig{'ROOT_USER'},
            ADM_GROUP     => $main::imscpConfig{'ADM_GROUP'},
            HTTPD_LOG_DIR => $self->{'config'}->{'HTTPD_LOG_DIR'},
            PHP_VERSION   => $self->{'CONFIG'}->{'PHP_VERSION'}
        }
    );

    $rs ||= $self->{'httpd'}->buildConfFile(
        'logrotate.conf',
        { },
        { destination => "$main::imscpConfig{'LOGROTATE_CONF_DIR'}/apache2" }
    );
    $rs ||= $self->{'eventManager'}->trigger( 'afterHttpdInstallLogrotate', 'apache2' );
}

=item _setupVlogger()

 Setup vlogger

 Return int 0 on success, other on failure

=cut

sub _setupVlogger
{
    my $self = shift;

    my $sqld = Servers::sqld->factory();
    my $host = main::setupGetQuestion( 'DATABASE_HOST' );
    $host = $host eq 'localhost' ? '127.0.0.1' : $host;
    my $port = main::setupGetQuestion( 'DATABASE_PORT' );
    my $dbName = main::setupGetQuestion( 'DATABASE_NAME' );
    my $user = 'vlogger_user';
    my $userHost = main::setupGetQuestion( 'DATABASE_USER_HOST' );
    $userHost = '127.0.0.1' if $userHost eq 'localhost';
    my $oldUserHost = $main::imscpOldConfig{'DATABASE_USER_HOST'} || '';
    my $pass = randomStr(16, iMSCP::Crypt::ALNUM);

    my $db = iMSCP::Database->factory();
    my $rs = main::setupImportSqlSchema( $db, "$self->{'apacheCfgDir'}/vlogger.sql" );
    return $rs if $rs;

    for ($userHost, $oldUserHost, 'localhost') {
        next unless $_;
        $sqld->dropUser( $user, $_ );
    }

    $sqld->createUser( $user, $userHost, $pass );

    # No need to escape wildcard characters. See https://bugs.mysql.com/bug.php?id=18660
    my $qDbName = $db->quoteIdentifier( $dbName );
    $rs = $db->doQuery( 'g', "GRANT SELECT, INSERT, UPDATE ON $qDbName.httpd_vlogger TO ?\@?", $user, $userHost );
    unless (ref $rs eq 'HASH') {
        error( sprintf( 'Could not add SQL privileges: %s', $rs ) );
        return 1;
    }

    $self->{'httpd'}->setData(
        {
            DATABASE_NAME     => $dbName,
            DATABASE_HOST     => $host,
            DATABASE_PORT     => $port,
            DATABASE_USER     => $user,
            DATABASE_PASSWORD => $pass
        }
    );

    $self->{'httpd'}->buildConfFile(
        "$self->{'apacheCfgDir'}/vlogger.conf.tpl",
        { SKIP_TEMPLATE_CLEANER => 1 },
        { destination => "$self->{'apacheCfgDir'}/vlogger.conf" }
    );
}

=item _saveConf()

 Save configuration file

 Return int 0 on success, other on failure

=cut

sub _saveConf
{
    my $self = shift;

    (tied %{$self->{'config'}})->flush();
    (tied %{$self->{'phpConfig'}})->flush();

    my %filesToDir = (
        'apache' => $self->{'apacheCfgDir'},
        'php'    => $self->{'phpCfgDir'}
    );

    for (keys %filesToDir) {
        my $rs = iMSCP::File->new( filename => "$filesToDir{$_}/$_.data" )->copyFile( "$filesToDir{$_}/$_.old.data" );
        return $rs if $rs;
    }

    0;
}

=item _cleanup()

 Process cleanup tasks

 Return int 0 on success, other on failure

=cut

sub _cleanup
{
    my $self = shift;

    my $rs = $self->{'httpd'}->disableSites( 'imscp.conf', '00_modcband.conf', '00_master.conf', '00_master_ssl.conf' );
    return $rs if $rs;

    for ('imscp.conf', '00_modcband.conf', '00_master.conf', '00_master_ssl.conf') {
        next unless -f "$self->{'config'}->{'HTTPD_SITES_AVAILABLE_DIR'}/$_";
        $rs = iMSCP::File->new( filename => "$self->{'config'}->{'HTTPD_SITES_AVAILABLE_DIR'}/$_" )->delFile();
        return $rs if $rs;
    }

    for ('/var/log/apache2/backup', '/var/log/apache2/users', '/var/www/scoreboards') {
        $rs = iMSCP::Dir->new( dirname => $_ )->remove();
        return $rs if $rs;
    }

    # Remove customer's logs file if any (no longer needed since we are now use bind mount)
    $rs = execute( "rm -f $main::imscpConfig{'USER_WEB_DIR'}/*/logs/*.log", \ my $stdout, \ my $stderr );
    debug($stdout) if $stdout;
    error( $stderr || 'Unknown error' ) if $rs;
    return $rs if $rs;

    #
    ## Cleanup and disable unused PHP versions/SAPIs
    #

    if (-f '/etc/logrotate.d/php5-fpm') {
        $rs = iMSCP::File->new( filename => '/etc/logrotate.d/php5-fpm' )->delFile();
        return $rs if $rs;
    }

    $rs = iMSCP::Dir->new( dirname => '/etc/php5' )->remove();
    return $rs if $rs;

    for(grep !/^$self->{'phpConfig'}->{'PHP_CONF_DIR_PATH'}$/, glob dirname($self->{'phpConfig'}->{'PHP_CONF_DIR_PATH'}).'/*') {
        $rs = iMSCP::Dir->new( dirname => $_ )->remove();
        return $rs if $rs;
    }

    # CGI
    $rs = iMSCP::Dir->new( dirname => $self->{'phpConfig'}->{'PHP_FCGI_STARTER_DIR'} )->remove();
    return $rs if $rs;

    # FPM
    unlink grep !/www\.conf$/, glob "$self->{'phpConfig'}->{'PHP_FPM_POOL_DIR_PATH'}/*.conf";
    local $@;
    eval {
        my $serviceMngr = iMSCP::Service->getInstance();
        $serviceMngr->stop( sprintf( 'php%s-fpm', $self->{'phpConfig'}->{'PHP_VERSION'} ) );
        $serviceMngr->disable( sprintf( 'php%s-fpm', $self->{'phpConfig'}->{'PHP_VERSION'} ) );
    };
    if ($@) {
        error( $@ );
        return 1;
    }

    0;
}

=back

=head1 AUTHOR

 Laurent Declercq <l.declercq@nuxwin.com>

=cut

1;
__END__
