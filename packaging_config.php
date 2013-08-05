$configure = array(
    'packagename' => 'codebender-arduino-compiler',
    'arch' => 'all',
    'version' => '1.0',
    'maintainer' => 'Vasilis Georgitzikis <tzikis@gmai.com>',
    'description' => 'A RESTful compiler for Arduino cores',
    'url' => 'htpp://github.com/codebendercc/compiler',
    'packagetype' => 'deb',
    'depends' => array(
        'apache2',
        'libapache2-mod-php5',
	'php-pear',
	'clang',
	'gcc-avr',
	'avr-libc',
	'binutils-avr',
	'acl'
    ),

    'tmpdir' => '/tmp',
    'templatedir' => 'scripts',
    'postinst' => 'scripts/postinst.sh',
    'preinst' => '',
    'postrm' => 'scripts/postrm.sh',
    'prerm' => '',
    'debconfconfig' => '', // only for debian: config file for debconf
    'debconftemplate' => '', // only for debian: template file for debconf
    'configfile' => '', // mark a file as configuration file
);

/* here you can define which files or directories should go where in the target system.
 * You can use placeholders defined in your $configure array
 * The syntax is dest => src so you don't have to repeat dest if you have lots
 * of stuff to put in the same directory
 * To prevent some files or directories from ending up in the package you can exclude
 * them by prepending them with '- ' (see also example).
 *
 * Example:
 *
 * $filemapping = array(
 *   'var/www/@PACKAGENAME@' => array(
 *      'app/',
 *   )
 * )
 */
$filemapping = array(
    'opt/codebender/@PACKAGENAME@' => array(
        '*',
        '- /templates',
    ),
);
