<?php
/**
 * @package     Mautic
 * @copyright   2014 Mautic, NP. All rights reserved.
 * @author      Mautic
 * @link        http://mautic.com
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\CoreBundle\Security\Permissions;

use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\DependencyInjection\Container;
use Doctrine\ORM\EntityManager;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Class UserPermissions
 *
 * @package Mautic\UserBundle\Security\Permissions
 */
class CommonPermissions {

    protected  $permissions = array();
    protected  $em;
    protected  $container;

    public function __construct(Container $container, EntityManager $em) {
        $this->container = $container;
        $this->em        = $em;
    }

    /**
     * Returns bundle's permissions array
     *
     * @return array
     */
    public function getPermissions()
    {
        return $this->permissions;
    }

    /**
     * Checks to see if the requested permission is supported by the bundle
     *
     * @param $name
     * @param $perm
     * @return bool
     */
    public function isSupported($name, $level = '')
    {
        list($name, $level) = $this->getSynonym($name, $level);

        if (empty($level)) {
            //verify permission name only
            return isset($this->permissions[$name]);
        } else {
            //verify permission name and level as well
            return isset($this->permissions[$name][$level]);
        }
    }

    /**
     * Allows permission classes to be disabled if criteria is not met (such as bundle is disabled)
     *
     * @return bool
     */
    public function isEnabled() {
        return true;
    }

    /**
     * Returns the value assigned to a specific permission
     *
     * @param $name
     * @param $perm
     */
    public function getValue($name, $perm)
    {
        return ($this->isSupported($name, $perm)) ? $this->permissions[$name][$perm] : 0;
    }

    /**
     * Builds the bundle's specific form elements for its permissions
     *
     * @param FormBuilderInterface $builder
     * @param array                $options
     * @param array                $data
     */
    public function buildForm(FormBuilderInterface &$builder, array $options, array $data)
    {

    }

    /**
     * Returns the name of the permission set (should be the bundle identifier)
     *
     * @return string|void
     */
    public function getName()
    {
        return 'undefined';
    }

    /**
     * Takes an array from PermissionRepository::getPermissionsByRole() and converts the bitwise integers to an array
     * of permission names that can be used in forms, for example.
     *
     * @param array $perms
     * @return mixed
     */
    public function convertBitsToPermissionNames(array $permissions) {
        static $permissionLevels = array();
        $bundle = $this->getName();

        if (!in_array($bundle, $permissionLevels)) {
            $permissionLevels[$bundle] = array();
            if (isset($permissions[$bundle])) {
                if ($this->isEnabled()) {
                    foreach ($permissions[$bundle] as $permId => $details) {
                        $permName    = $details['name'];
                        $permBitwise = $details['bitwise'];
                        //ensure the permission still exists
                        if ($this->isSupported($permName)) {
                            $levels = $this->permissions[$permName];
                            //ensure that at least keys exist
                            $permissionLevels[$bundle][$permName]                      = array();
                            //$permissionLevels[$bundle][$permName]["$bundle:$permName"] = $permId;
                            foreach ($levels as $levelName => $levelBit) {
                                //compare bit against levels to see if it is a match
                                if ($levelBit & $permBitwise) {
                                    //bitwise compares so add the level
                                    $permissionLevels[$bundle][$permName][] = $levelName;
                                    continue;
                                }
                            }
                        }
                    }
                }
            }
        }
        return $permissionLevels[$bundle];
    }

    /**
     * Allows the bundle permission class to utilize synonyms for permissions
     *
     * @param $name
     * @param $level
     * @return array
     */
    protected function getSynonym($name, $level) {
        return array($name, $level);
    }

    /**
     * Determines if the user has access to the specified permission
     *
     * @param $userPermissions
     * @param $name
     * @param $level
     * @return int
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     */
    public function isGranted($userPermissions, $name, $level) {
        list($name, $level) = $this->getSynonym($name, $level);

        if (!isset($userPermissions[$name])) {
            //the user doesn't have implicit access
            return 0;
        } elseif ($this->permissions[$name]['full'] & $userPermissions[$name]) {
            return 1;
        } else {
            //otherwise test for specific level
            return ($this->permissions[$name][$level] & $userPermissions[$name]);
        }
    }

    /**
     * Gives the bundle the opportunity to force certain permissions if another is selected
     *
     * @param string $level
     * @param array $perms
     * @return array $updatedPerms
     */
    public function analyzePermissions($level, array $perms)
    {
        $updatedPerms = $perms;
        foreach ($perms as $perm) {
            switch ($perm) {
                case 'editother':
                case 'edit':
                    $required = array('viewother', 'viewown');
                    break;
                case 'editown':
                    $required = array('viewown');
                    break;
                case 'deleteother':
                case 'delete':
                    $required = array('editother', 'viewother', 'viewown');
                    break;
                case 'deleteown':
                    $required = array('viewown');
                    break;
                case 'create':
                    $required = array('viewown');
                    break;
            }

            if (!empty($required)) {
                foreach ($required as $r) {
                    list($ignore, $r) = $this->getSynonym($level, $r);
                    if ($this->isSupported($level, $r) && !in_array($r, $updatedPerms)) {
                        $updatedPerms[] = $r;
                    }
                }
            }
        }

        return $updatedPerms;
    }

    /**
     * Generates an array of granted and total permissions
     *
     * @param array $data
     * @return array
     */
    public function getPermissionRatio(array $data)
    {
        $totalAvailable = $totalGranted = 0;

        foreach ($this->permissions as $level => $perms) {
            $perms = array_keys($perms);
            $totalAvailable += count($perms);

            if (in_array('full', $perms)) {
                if (count($perms) === 1) {
                    //full is the only permission so count as 1
                    if (!empty($data[$level]) && in_array('full', $data[$level]))
                        $totalGranted++;
                } else {
                    //remove full from total count
                    $totalAvailable--;
                    if (!empty($data[$level]) && in_array('full', $data[$level])) {
                        //user has full access so sum perms minus full
                        $totalGranted += count($perms) - 1;
                        //move on to the next level
                        continue;
                    }
                }
            }

            if (isset($data[$level]))
                $totalGranted += count($data[$level]);

        }
        return array($totalGranted,$totalAvailable);
    }

    /**
     * Gives the bundle an opportunity to change how javascript calculates permissions granted
     *
     * @param array $perms
     */
    public function parseForJavascript(array &$perms)
    {
        //...
    }
}