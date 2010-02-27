<?php
/*******************************************************************************
 * PHP ClassMixer
 *
 * Authors:: anthony.gallagher@wellspringworldwide.com
 *
 * Copyright:: Copyright 2009, Wellspring Worldwide, LLC Inc. All Rights Reserved.
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *      http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 *
 * Description:
 * ===========
 * ClassMixer was inspired by the 'Mixins for PHP' article
 * at http://www.advogato.org/article/470.html
 * and the Common Lisp Object System (CLOS).
 *
 *
 * Using Mixins:
 * ============
 * ClassMixer can be used to mix into a base class the functionality of multiple
 * other 'mixin' classes. The resulting new class contains all the methods and properties
 * of the base class, plus all the public methods and properties of the mixin
 * classes added to the mix.
 *
 *
 * Method Combinations:
 * ===================
 * In addition to adding mixin methods into a combined result class, ClassMixer allows
 * the mixed class to define how to combine the method results when more than one of the 'parent'
 * classes defines the same method.
 *
 *
 * Method Cutpoints:
 * ================
 * ClassMixer allows 'before' and 'after' cutpoints to be inserted into the mixed class' methods.
 * This limited form of Aspect-Oriented Programming allows mixin classes to define methods
 * will be called before or after the called method is executed.
 ******************************************************************************/


/*******************************************************************************
 * Collection of helper functions for the ClassMixer
 ******************************************************************************/
abstract class CM_Utils {
    /**
     * Computes whether the system has the minimum required PHP version.
     *
     * @param string $min_version_str
     * @return boolean
     */
    public static function php_min_version($min_version_str)
    {
        //Get the minimum version desired, and the PHP version of the system
        $min_version = explode('.', $min_version_str);
        $current_version = explode('.', PHP_VERSION);

        //Figure out if the system meets the minimum version
        if ($current_version[0] > $min_version[0]) {
            return true;
        }
        elseif ($current_version[0] == $min_version[0]) {
            if (empty($min_version[1]) || $min_version[1] == '*' ||
                $current_version[1] > $min_version[1]) {
                return true;
            }
            elseif ($current_version[1] == $min_version[1]) {
                if (empty($min_version[2]) || $min_version[2] == '*' ||
                    $current_version[2] >= $min_version[2]) {
                    return true;
                }
            }
        }

        return false;
    }
}

/*******************************************************************************
 * Collection of combinator functions for the ClassMixer
 ******************************************************************************/
abstract class CM_Combinators {
    /**
     * A do-nothing function. Can be used to as a 'combinator' to
     * combine several functions that need to be called sequentially.
     */
    public static function execute() {}

    /**
     * This function will return the last of the result values returned by
     *    the base classes of a mixed class
     *
     * @return <type> Last base class result
     */
    public static function last() {
        $args = func_get_args();
        if (!empty($args)) {
            $num_args = func_num_args();
            return $args[$num_args-1];
        }
        return null;
    }
}



/*******************************************************************************
 * The ClassMixer class.
 * Contains functions to dynamically create classes by mixing
 * a set of existing classes.
 ******************************************************************************/

abstract class ClassMixer {
    /***************************************************************************
     * PHP 5+ mixed method creation routines.
     **************************************************************************/
    /**
     * Generates code to be inserted in a generated method of the mixed class
     * that calls the BEFORE cutpoint of this generated method, if any.
     *
     * @param string $new_class
     * @param string $method
     * @param array $args_params
     * @return string Generated code to call the BEFORE cutpoint for the method.
     */
    private static function form_before_cutpoint_call5($new_class, $method, $args_params) {
        $has_args = (strlen($args_params) > 0);
        $bm_args_params =  $has_args ? $args_params.", \$ret" : "\$ret";
        $ba_args_params = $has_args ? "'$new_class::$method', ".$args_params : "'$new_class::$method'";
        
        $cutpoint_code = "
                //Do before method calls
                if (in_array('BEFORE_ALL', get_class_methods('$new_class'))) {
                    $new_class::BEFORE_ALL($ba_args_params);
                }
                if (in_array('BEFORE_$method', get_class_methods('$new_class'))) {
                    \$ret = null;   
                    $new_class::\$__mixer_var =& \$ret;

                    $new_class::BEFORE_$method($bm_args_params);

                    if (!is_null($new_class::\$__mixer_var)) {
                        return $new_class::\$__mixer_var;
                    }
                }
                ";
        return $cutpoint_code;
    }

    /**
     * Generates code to be inserted in a generated method of the mixed class
     * that calls the BEFORE cutpoint of this generated method, if any.
     *
     * @param string $new_class
     * @param string $method
     * @param array $args_params
     * @return string Generated code to call the AFTER cutpoint for the method.
     */
    private static function form_after_cutpoint_call5($new_class, $method, $args_params) {
        $has_args = (strlen($args_params) > 0);
        $am_args_params = $has_args ? $args_params.", \$ret" : "\$ret";
        $aa_args_params = $has_args ? "'$new_class::$method', ".$args_params.", \$ret" : "'$new_class::$method', \$ret";
        
        $cutpoint_code = "
                //Do after method calls
                if (in_array('AFTER_$method', get_class_methods('$new_class'))) {
                    $new_class::\$__mixer_var =& \$ret;

                    $new_class::AFTER_$method($am_args_params);

                    \$ret =& $new_class::\$__mixer_var;
                }
                if (in_array('AFTER_ALL', get_class_methods('$new_class'))) {
                    $new_class::AFTER_ALL($aa_args_params);
                }
                ";
        return $cutpoint_code;
    }

    /**
     * Create the argument list for a method, given the reflection method.
     * This function analyzes the given method using the Reflection API to form two
     * strings:
     * (1) The method signature and
     * (2) A parameter list.
     * E.g. For a method with signature:
     *    function foo($a, &$b, $c=5) { ... }
     * this function will return:
     * array('$a, &$b, $c=5', '$a, $b, $c')
     *
     * @param ReflectionMethod $reflect_method
     * @return array
     */
    public static function form_method_argument_list5($reflect_method) {
        $args_signature = array();
        $args_params = array();
        $reflect_params = $reflect_method->getParameters();

        //Obtain information about the parameter list of the method
        foreach ($reflect_params as $p) {
            //Get the name of the parameter
            $param_name = $p->getName();
            $args_params[] = '$'.$param_name;

            //Get the signature of the parameter to form the method signature
            $arg = '';
            $ref = $p->isPassedByReference() ? '&' : '';
            $arg .= $ref;
            $arg .= '$'.$param_name;
            if ($p->isOptional()) {
                $arg .= '=';
                $arg .= var_export($p->getDefaultValue(), true);
            }

            $args_signature[] = $arg;
        }

        //Returns (1) a string replicating the method signature that can be used when
        //   defining the mixed method, and (2) a string listing the names of the parameters
        //   that can be used when calling the method.
        return array(implode(', ', $args_signature), implode(', ', $args_params));
    }

    /**
     * Creates a mixed method for the new mixed class.
     *
     * This function uses the Reflection API to obtain the parameter lists, which
     * properly finds parameters need to be passed by reference.
     * The generated code should also be faster than the equivalent PHP 4
     * method, since it does not need to use eval().
     *
     * @param string $new_class
     * @param string $method
     * @param array $bases
     * @param array $combinators
     * @param boolean $before_cutpoint
     * @param boolean $after_cutpoint
     * @return string Generated code for a method of the mixed class.
     */
    private static function form_class_method5($new_class, $method, $bases, $combinators=array(),
                                               $before_cutpoint=false, $after_cutpoint=false) {
        //When the method is a global cutpoint! Need to use old style PHP 4 mixed methods, as
        //   these methods don't have an argument signature
        if (self::is_method_global_cutpoint($method)) {
            return self::form_class_method4($new_class, $method, $bases, $combinators,
                                            $before_cutpoint, $after_cutpoint);
        }

        //Get the method parameter list (this assumes that all the methods to be
        //   combined have the same signature!)
        $b = $bases[0];
        $reflect_method = new ReflectionMethod($b, $method);

        //Get the access modifiers
        $is_protected = $reflect_method->isProtected() ? 'protected' : '';
        $is_static = $reflect_method->isStatic() ? 'static' : '';
        $method_modifiers = $is_protected.' '.$is_static;

        //Get the return type (by value or reference)
        $return_type = $reflect_method->returnsReference() ? '&' : '';

        //Get the parameter list
        $args = self::form_method_argument_list5($reflect_method);
        $args_signature = $args[0];
        $args_params = $args[1];

        //Form the method signature
        $method_signature = "$method_modifiers function$return_type $method($args_signature)";

        //Create the before and after method calls, if any
        $is_method_cutpoint = self::is_method_cutpoint($method);
        $before_code = ($before_cutpoint && !$is_method_cutpoint) ?
                            self::form_before_cutpoint_call5($new_class, $method, $args_params) : '';
        $after_code = ($after_cutpoint && !$is_method_cutpoint) ?
                            self::form_after_cutpoint_call5($new_class, $method, $args_params) : '';

	//Get the combinator info
        list($combinator_name, $ordered_bases) = self::parse_combinator_info($method, $bases, $combinators);            

        $func_code = '';
        //By default, if no combinators, just execute the method call of the first base.
        if (is_null($combinator_name) || sizeof($ordered_bases) == 1) {
            $b = $ordered_bases[0];

            $func_code = "
            $method_signature {
                $before_code

                //Do method call
                \$ret =& $b::$method($args_params);

                $after_code

                //Return value
                return \$ret;
            }";
        }
        else {
            //Create the call string
            $func_array = array();
            foreach ($ordered_bases as $b) {
                $func_array[] = "$b::$method($args_params)";
            }
            $func_str = implode(', ', $func_array);

            $func_code = "
            $method_signature {
                $before_code

                //Do method call
                \$ret =& $combinator_name($func_str);

                $after_code

                //Return value
                return \$ret;
            }";
        }
        return $func_code;
    }

    /**
     * Generate a string with the variable definitions for the class
     *
     * @param array $mixins
     * @return string String of variable definitions
     */
    private static function form_class_variables5($mixins) {
        $props_arr = array();
        $props_arr['__mixer_var'] = 'static $__mixer_var;';
        foreach ($mixins as $mixin) {
            //Get the property array
            $mixin_ref = new ReflectionClass($mixin);
            $props = $mixin_ref->getProperties();
            $prop_defaults = $mixin_ref->getDefaultProperties();

            //Create the property definitions
            foreach($props as $prop){
                //Get the property name and value
                $prop_name = $prop->getName();
                $prop_value = $prop_defaults[$prop_name];

                //If it is already created, skip...
                if (isset($props_arr[$prop_name])) {
                    continue;
                }

                //Don't copy over statics. In mixed class, need to fully qualify the
                //   parent class when using statics
                if ($prop->isStatic()) {
                    continue;
                }

                //Create the property
                if (is_null($prop_value)) {
                    $props_arr[$prop_name] = "var \$$prop_name;";
                }
                else {
                    $props_arr[$prop_name] = "var \$$prop_name = ".var_export($prop_value, true).";";
                }

                //Mark previously private and protected variables. Copying them over
                //   as private is necessary so that they are accessible in the 'parent'
                //   mixin class.
                if ($prop->isProtected()) {
                    $props_arr[$prop_name] .= ' //was protected';
                }
                elseif($prop->isPrivate()) {
                    $props_arr[$prop_name] .= ' //was private';
                }
            }
        }
        
        //Return the string of variables
        return implode("\n\t", $props_arr);
    }

    /***************************************************************************
     * PHP 4.2+ mixed method creation routines.
     **************************************************************************/
    /**
     * Generates code to be inserted in a generated method of the mixed class
     * that calls the BEFORE cutpoint of this generated method, if any.
     *
     * @param string $new_class
     * @param string $method
     * @return string
     */
    private static function form_before_cutpoint_call4($new_class, $method) {
        $cutpoint_code = "
                //Do before method calls
                \$has_args = (strlen(\$arg_str) > 0);
                if (in_array('BEFORE_ALL', get_class_methods('$new_class'))) {
                    \$ba_arg_str = \$has_args ?
                             \"'$new_class::$method', \".\$arg_str : \"'$new_class::$method'\";
                    eval('$new_class::BEFORE_ALL('.\$ba_arg_str.');');
                }
                if (in_array('BEFORE_$method', get_class_methods('$new_class'))) {
                    \$ret = null;

                    eval('$new_class::BEFORE_$method('.\$arg_str.');');
                }
                ";
        return $cutpoint_code;
    }

    /**
     * Generates code to be inserted in a generated method of the mixed class
     * that calls the AFTER cutpoint of this generated method, if any.
     *
     * @param string $new_class
     * @param string $method
     * @return string
     */
    private static function form_after_cutpoint_call4($new_class, $method) {
        $cutpoint_code = "
                //Do after method call
                \$has_args = (strlen(\$arg_str) > 0);
                if (in_array('AFTER_$method', get_class_methods('$new_class'))) {
                    eval('$new_class::AFTER_$method('.\$arg_str.');');
                }
                if (in_array('AFTER_ALL', get_class_methods('$new_class'))) {
                    \$aa_arg_str = \$has_args ?
                             \"'$new_class::$method', \".\$arg_str.\", \\\$ret\" : \"'$new_class::$method', \\\$ret\";
                    eval('$new_class::AFTER_ALL('.\$aa_arg_str.');');
                }
                ";
        return $cutpoint_code;
    }


    /**
     * Form a string with the argument list for the methods called in mixer functions.
     * Needs to be public, because it is called from the mixed method.
     *
     * @param array $args
     * @return string
     */
    public static function form_method_argument_list4($args) {
        $argStrArr = array();
        foreach(array_keys($args) as $key) {
            $argStrArr[] = '$args['.$key.']';
        }
        return implode($argStrArr, ', ');
    }

    /**
     * Creates a mixed method for a mixin-based class.
     *
     * This function does not use the Reflection API introduced in
     * PHP 5, and it has limitations
     * with regards to the type of method that can be mixed.
     * Methods that accept reference variables are problematic. The mixed method loses
     * the reference, passing all arguments by value.
     *
     * @param string $new_class
     * @param string $method
     * @param array $bases
     * @param array $combinators
     * @param boolean $before_cutpoint
     * @param boolean $after_cutpoint
     * @return string Generated code for a method of the mixed class.
     */
    private static function form_class_method4($new_class, $method, $bases, $combinators=array(),
                                               $before_cutpoint=false, $after_cutpoint=false) {
        //Create the before and after method calls, if any
        $is_method_cutpoint = self::is_method_cutpoint($method);
        $before_code = ($before_cutpoint && !$is_method_cutpoint) ?
                            self::form_before_cutpoint_call4($new_class, $method) : '';
        $after_code = ($after_cutpoint && !$is_method_cutpoint) ?
                            self::form_after_cutpoint_call4($new_class, $method) : '';

        //Get the combinator information
        list($combinator_name, $ordered_bases) = self::parse_combinator_info($method, $bases, $combinators);

        $func_code = '';
        //By default, if no combinators, just execute the method call of the first base.
        if (is_null($combinator_name) || sizeof($ordered_bases) == 1) {
            $b = $ordered_bases[0];

            $func_code = "
            function& $method() {
                //Get arguments for the method
                \$args = func_get_args();
                \$arg_str = ClassMixer::form_method_argument_list4(\$args);

                $before_code

                //Do method call
                eval('\$ret =& $b::$method('.\$arg_str.');');

                $after_code

                //Return value
                return \$ret;
            }";
        }
        else {
            $func_array = array();
            foreach ($ordered_bases as $b) {
                $func_array[] = "$b::$method("."'.\$arg_str.'".")";
            }
            $func_str = implode(', ', $func_array);

            $func_code = "
            function& $method() {
                //Get arguments for the method
                \$args = func_get_args();
                \$arg_str = ClassMixer::form_method_argument_list4(\$args);

                $before_code

                //Do method call
                eval('\$ret =& $combinator_name($func_str);');

                $after_code

                //Return value
                return \$ret;
            }";
        }
        return $func_code;
    }

    /**
     * Generate a string with the variable definitions for the class
     *
     * @param array $mixins
     * @return string String of variable definitions
     */
    private static function form_class_variables4($mixins) {
        $pub_vars = array();
        foreach ($mixins as $mixin) {
            foreach(get_class_vars($mixin) as $pub_var => $val) {
                //Already created, continue...
                if (isset($pub_vars[$pub_var])) {
                    continue;
                }
                //Create the class variable
                if (is_null($val)) {
                    //No associated value, just add the class variable.
                    $pub_vars[$pub_var] = "var \$$pub_var;";
                }
                else {
                    //There is an associated value, define the class variable and copy the value.
                    $pub_vars[$pub_var] = "var \$$pub_var = ".var_export($val, true).";";
                }
            }
        }
        //Return the string of variables
        return implode("\n\t", $pub_vars);
    }

    /***************************************************************************
     * Interface functions to abstract the PHP version from the main mixer methods
     **************************************************************************/
    /**
     * Predicate to test if a method is a global cutpoint
     * 
     * @param string $method
     * @return boolean True if the method is a global cutpoint.
     */
    private static function is_method_global_cutpoint($method) {
        return (strpos($method, 'BEFORE_ALL') === 0 || strpos($method, 'AFTER_ALL') === 0);
    }

    /**
     * Predicate to test if a method is a cutpoint
     * 
     * @param string $method
     * @return boolean True if the method is a cutpoint. 
     */
    private static function is_method_cutpoint($method) {
        return (strpos($method, 'BEFORE_') === 0 || strpos($method, 'AFTER_') === 0);
    }

    /**
     * Obtain the combinator information for a mixed method.
     * A simple combinator just specifies the combinator method to use
     * A complex combinator contains an array specifying the combinator method plus
     *    which base classes to combine in what order
     *
     * @param string $method
     * @param array $bases
     * @return array Two-element array with the combinator method and the ordered list of bases
     */
    private static function parse_combinator_info($method, $bases, $combinators) {
        //If a combinator was given, parse the information.
        if (isset($combinators[$method])) {
            $combinator_info = $combinators[$method];
            if (is_array($combinator_info)) {
                $combinator_name = $combinator_info[0];
                $combinator_bases = $combinator_info[1];
                $ordered_bases = array_intersect($combinator_bases, $bases);
            }
            else {
                $combinator_name = $combinator_info;
                $ordered_bases = $bases;
            }
            return array($combinator_name, $ordered_bases);
        }
        //No combinator, just return the bases
        return array(null, $bases);
    }

    /**
     * Obtains the list of methods on this class that are eligible for mixing
     * 
     * @param string $klass
     * @param boolean $is_parent
     * @return array List of methods eligible for mixing
     */
    private static function available_base_class_methods($klass, $is_parent=false) {
        $php5_available = CM_Utils::php_min_version('5');

        //Obtain the list of mixable methods
        if ($php5_available) {
            //Only allow all public methods for mixin classes, and public and protected
            //   methods for the base class
            $available_methods = array();
            $reflect_klass = new ReflectionClass($klass);
            $reflect_methods = $reflect_klass->getMethods();
            foreach ($reflect_methods as $rm) {
                if ($rm->isAbstract() || ($is_parent && $rm->isFinal()) ||
                    $rm->isConstructor() || $rm->isDestructor() ||
                    $rm->isPrivate() || (!$is_parent && $rm->isProtected())) {
                    continue;
                }
                $available_methods[] = $rm->getName();
            }
            return $available_methods;
        }
        else {
            //Get all methods (there were no access modifiers in PHP 4)
            return get_class_methods($klass);
        }
    }

    /**
     * Creates a mixed method for a mixin-based class.
     * 
     * @param <type> $new_class
     * @param <type> $method
     * @param <type> $bases
     * @param <type> $combinators
     * @param <type> $before_cutpoint
     * @param <type> $after_cutpoint
     * @return <type>
     */
    private static function form_class_method($new_class, $method, $bases, $combinators=array(),
                                              $before_cutpoint=false, $after_cutpoint=false) {
        $php5_available = CM_Utils::php_min_version('5');
        if ($php5_available) {
            return self::form_class_method5($new_class, $method, $bases, $combinators,
                                            $before_cutpoint, $after_cutpoint);
        }
        else {
            return self::form_class_method4($new_class, $method, $bases, $combinators,
                                            $before_cutpoint, $after_cutpoint);
        }
    }

    /**
     * Generate a string with the variable definitions for the class
     *
     * @param array $mixins
     * @return string String of variable definitions
     */
    private static function form_class_variables($mixins) {
        $php5_available = CM_Utils::php_min_version('5');
        if ($php5_available) {
            return self::form_class_variables5($mixins);
        }
        else {
            return self::form_class_variables4($mixins);
        }
    }

    /***************************************************************************
     * Mixed class creation routines.
     **************************************************************************/
    /**
     * This is the core mixer function.
     * This function generates an eval-uable string that defines a new mixed class.
     * The mixed class combines the methods of the mixin classes with those
     * of the base class to produce a new class with all the methods of all
     * the 'parent' classes.
     *
     * Combinators:
     * Collisions of method names are resolved using the combinators. When
     * no combinator is given, and more than one class define a method,
     * the method of the first class will be used.
     *
     * Cutpoints:
     * BEFORE and AFTER cutpoints can be inserted on the methods specified
     * in the cutpoints arrays.
     *
     * @param string $new_class
     * @param string $base
     * @param array $mixins
     * @param array $combinators
     * @param array $before_cutpoints
     * @param array $after_cutpoints
     * @return string Generated mixed class.
     */
    public static function form_mixed_class($new_class, $base, $mixins, $combinators=array(),
                                            $before_cutpoints=array(), $after_cutpoints=array()) {
        //Check for PHP version
        if (!CM_Utils::php_min_version('4.2')) {
            throw Exception('ClassMixer requires PHP 4.2 or above');
        }

        //Get the interfaces implements by the base class and the mixins
        $interfaces = class_implements($base);
        foreach ($mixins as $mixin) {
            $interfaces = array_merge($interfaces, class_implements($mixin));
        }
        $str_interfaces = implode(', ', array_values($interfaces));

        $class_header = "
        class $new_class extends $base";

        if (strlen($str_interfaces) > 0) {
            // if we have interfaces add it to the class header
            $class_header .= " implements $str_interfaces";
        }

        //Add the mixin variables 
        $str_var_code = self::form_class_variables($mixins);

        //Get the functions
        $funcs = array();
        $func_code = array();
        $based_on = array_merge(array($base), $mixins);
        foreach($based_on as $b) {
            foreach(self::available_base_class_methods($b, $b==$base) as $bm) {
                //Add the class as holding method $bm
                if (isset($funcs[$bm]))
                    //Add the class $b as having method $bm to an existing array
                    $funcs[$bm][] = $b;
                else {
                    //Create an array that denotes that class $b has method $bm
                    $funcs[$bm] = array($b);
                }
            }
        }

        //Get the names of all non-cutpoint functions
        $func_names = array();
        foreach (array_keys($funcs) as $func_name) {
            if (!self::is_method_cutpoint($func_name)) {
                $func_names[] = $func_name;
            }
        }

        //Add the combinators for the before and after cutpoints
        if ($before_cutpoints === true) {
            $before_cutpoints = $func_names;
            $before_cutpoints[] = 'ALL';
        }
        foreach ($before_cutpoints as $bc) {
            $bm = 'BEFORE_'.$bc;
            if (!array_key_exists($bm, $combinators)) {
                $combinators[$bm] = 'CM_Combinators::execute';
            }
        }
        if ($after_cutpoints === true) {
            $after_cutpoints = $func_names;
            $after_cutpoints[] = 'ALL';
        }
        foreach ($after_cutpoints as $ac) {
            $bm = 'AFTER_'.$ac;
            if (!array_key_exists($bm, $combinators)) {
                $combinators[$bm] = 'CM_Combinators::execute';
            }
        }

        //Create the methods for the mixed class
        foreach($funcs as $bm => $klasses) {
            //Allow before or after cutpoints for this method
            $before_cutpoint = in_array($bm, $before_cutpoints);
            $after_cutpoint = in_array($bm, $after_cutpoints);
            //We only need to create a method if it there are more than one parent class with
            //   the given method, or the parent class with the method is not the base class
            if ($before_cutpoint || $after_cutpoint || sizeof($klasses) > 1 || $klasses[0] != $base) {
                $func_code[$bm] = self::form_class_method($new_class, $bm, $klasses, $combinators,
                                                          $before_cutpoint, $after_cutpoint);
            }
        }
        $str_func_code = implode("\n", $func_code);

        //Start the class construction
        $code = "
        $class_header {
            $str_var_code
            $str_func_code
        }";

        return $code;
    }

    /**
     * Entry-point mixer function.
     * Simply evaluates the mixed class definition generated by form_mixed_class to
     * define the PHP class during runtime.
     *
     * @param string $new_class
     * @param string $base
     * @param array $mixins
     * @param array $combinators
     * @param array $before_cutpoints
     * @param array $after_cutpoints
     */
    public static function create_mixed_class($new_class, $base, $mixins, $combinators=array(),
                                              $before_cutpoints=array(), $after_cutpoints=array()) {
        $str_class = self::form_mixed_class($new_class, $base, $mixins, $combinators, $before_cutpoints, $after_cutpoints);
        eval($str_class);
    }

    /**
     * Second entry-point mixer function.
     * Loads a mixed class generated by form_mixed_class from an existing file, or
     * calls form_mixed_class and saves its output to the file
     * if it does not exists.
     * WARNING: The user running the PHP engine (typically Apache) must have
     * permissions to access this file.
     * If the file cannot be accessed, then fall-back to create_mixed_class
     *
     * @param string $mixed_class_file
     * @param boolean $rewrite
     * @param string $new_class
     * @param string $base
     * @param array $mixins
     * @param array $combinators
     * @param array $before_cutpoints
     * @param array $after_cutpoints
     */
    public static function require_mixed_class($mixed_class_file, $rewrite,
                                               $new_class, $base, $mixins, $combinators=array(),
                                               $before_cutpoints=array(), $after_cutpoints=array()) {
        try {
            //Ensure we have a full path
            $mixed_class_file = realpath($mixed_class_file);
            if ($rewrite || !file_exists($mixed_class_file)) {
                //Try to open the file for writing
                $fh = fopen($mixed_class_file, 'w');
                if ($fh === false) {
                    throw Exception('ClassMixer could not write the cached class');
                }

                //Data to save to the file
                $header = "
                <?php
                /**
                 This file was auto-generated by the ClassMixer. DO NOT EDIT MANUALLY.
                 */\n\n";
                $str_class = self::form_mixed_class($new_class, $base, $mixins, $combinators, $before_cutpoints, $after_cutpoints);

                //Save data
                fwrite($fh, $header);
                fwrite($fh, $str_class);
                fclose($fh);
            }
            require_once($mixed_class_file);
        }
        catch (Exception $e) {
            //OK, could not create the cached version. Just create it dynamically
            self::create_mixed_class($new_class, $base, $mixins, $combinators, $before_cutpoints, $after_cutpoints);
        }
    }
}

