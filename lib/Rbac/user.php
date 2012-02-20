<?php
namespace Rbac;
if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/**
 * User class.
 *
 * Each user is identified by a unique email address that serves as their username.
 * 
 * @extends ActiveRecord
 */
class User extends \ActiveRecord\Model
{
	public $_rbac_cache;

	/* --------------------------------------------------
	 *	ACTIVERECORD ASSOCIATIONS
	 * ----------------------------------------------- */


	static $table_name = 'rbac_users';
	
	static $has_many = array(
		array('memberships'),
		array('groups', 'through' => 'memberships')
	);


	/* --------------------------------------------------
	 *	MODEL BEHAVIOURS (PUBLIC METHODS)
	 * ----------------------------------------------- */


	/**
	 * Get all the rules that apply to a user
	 *
	 * N.b. this method should only return the array of rules; the caching should be done elsewhere.
	 *
	 * Something more like:
	 *
	 * $_SESSION['rbac_cache'] = $user->get_rbac_profile(); 
	 * 
	 * @access public
	 * @return void
	 */
	public function get_rbac_profile()
	{
		$query = "
			SELECT
				`allowed`,
				t2.`id` AS `privilege`,
				t2.`singular` AS `is_granular_privilege`,
				t4.`id` AS `action`,
				t5.`id` AS `resource`,
				t5.`singular` AS `is_granular_resource`,
				t7.`id` AS `entity`,
				t8.`id` as `group`,
				t8.`importance`

			FROM `".Rule::$table_name."` AS t1

				-- Privileges Joins --
				INNER JOIN `".Privilege::$table_name."` AS t2
					ON t2.`id` = t1.`privilege_id`
				INNER JOIN `".Liberty::$table_name."` AS t3
					ON t3.`privilege_id` = t2.`id`
				INNER JOIN `".Action::$table_name."` AS t4
					ON t4.`id` = t3.`action_id`

				-- Resources Joins --
				INNER JOIN `".Resource::$table_name."` AS t5
					ON t5.`id` = t1.`resource_id`
				INNER JOIN `".Component::$table_name."` AS t6
					ON t6.`resource_id` = t5.`id`
				INNER JOIN `".Entity::$table_name."` AS t7
					ON t7.`id` = t6.`entity_id`

				-- Groups to user Joins --
				INNER JOIN `".Group::$table_name."` AS t8
					ON t8.`id` = t1.`group_id`
				INNER JOIN `".Membership::$table_name."` AS t9
					ON t9.`group_id` = t8.`id`

			WHERE
				`user_id` = '{$this->id}'

			ORDER BY
				t4.`id`,
				t7.`id`,
				t8.`importance` DESC
		";
echo '<pre>';
print_r($query);
		return array_reverse(Rule::find_by_sql($query));
	}


	/**
	 * Determine whether the user is a member of a group.
	 * 
	 * @access public
	 * @param Group $group
	 * @return boolean
	 */
	public function in_group(Group $group)
	{
		if (Membership::find_by_user_id_and_group_id($this->id, $group->id))
			return TRUE;
		
		return FALSE;
	}
	

	/**
	 * Determine whether a user is allowed to (action) with (entity)
	 * 
	 * @access public
	 * @param Entity $entity
	 * @param Action $action
	 * @return void
	 */
	public function is_allowed(Action $action, Entity $entity, $force_lookup = FALSE)
	{
		// first, we need an array of rules to traverse
		
		// if there is a permissions cache and we're not forcing a db lookup
		if ($this->_rbac_cache && ! $force_lookup ) {
			$rules = $this->_rbac_cache;
			echo 'cache lookup<br>';

		// perform a single lookup
		} else {
			// adjusted to search by action_id and entity_id, instead of names (which may not be unique)
			$query = "
				SELECT
					`allowed`,
					t2.`id` AS `privilege`,
					t2.`singular` AS `is_granular_privilege`,
					t4.`id` AS `action`,
					t5.`id` AS `resource`,
					t5.`singular` AS `is_granular_resource`,
					t7.`id` AS `entity`,
					t8.`id` AS `group`,
					t8.`importance`
				
				FROM `".Rule::$table_name."` AS t1
					
					-- Privileges Joins --
					INNER JOIN `".Privilege::$table_name."` AS t2
						ON t2.`id` = t1.`privilege_id` 
					INNER JOIN `".Liberty::$table_name."` AS t3
						ON t3.`privilege_id` = t2.`id`
					INNER JOIN `".Action::$table_name."` AS t4
						ON t4.`id` = t3.`action_id`
	
					-- Resources Joins --
					INNER JOIN `".Resource::$table_name."` AS t5
						ON t5.`id` = t1.`resource_id`
					INNER JOIN `".Component::$table_name."` AS t6
						ON t6.`resource_id` = t5.`id`
					INNER JOIN `".Entity::$table_name."` AS t7
						ON t7.`id` = t6.`entity_id`
	
					-- Groups to user Joins --
					INNER JOIN `".Group::$table_name."` AS t8
						ON t8.`id` = t1.`group_id`
					INNER JOIN `".Membership::$table_name."` AS t9
						ON t9.`group_id` = t8.`id`
	
				WHERE
					`user_id` = '{$this->id}'
						AND
					t4.`id` = '{$action->id}'
						AND
					t7.`id` = '{$entity->id}'
	
				ORDER BY
					t8.`importance` DESC,
					t8.`id`
				";

			$rules = array_reverse(Rule::find_by_sql($query));
		}

		$allowed = NULL;
		$importance_threshold = NULL;
		$weight_threshold = -1;

		do {
			$rule = array_pop($rules);

			if ($rule->action == $action->id && $rule->entity == $entity->id) {

				if ($rule->importance < $importance_threshold) {
					continue;
				}

				$weight = $rule->is_granular_privilege + $rule->is_granular_resource;
	
				if ($weight > $weight_threshold) {
					$allowed = $rule->allowed ? TRUE : FALSE;
					$weight_threshold = $weight;
	
				} else if ($weight == $weight_threshold && ! $rule->allowed)
					$allowed = FALSE;
			}

		} while ($rules);

		return $allowed;
	}
	

	/**
	 * Join a group.
	 * 
	 * @access public
	 * @param Group $group
	 * @return void
	 */
	public function join_group(Group $group)
	{
		$membership = new Membership();
		$membership->user_id = $this->id;
		$membership->group_id = $group->id;
		$membership->save();
	}
	

	/**
	 * Leave a group.
	 * 
	 * @access public
	 * @param Group $group
	 * @return void
	 */
	public function leave_group(Group $group)
	{
		return Membership::find_by_user_id_and_group_id($this->id, $group->id)->delete();
	}


	/* --------------------------------------------------
	 *	MODEL BEHAVIOURS (PUBLIC STATIC METHODS)
	 * ----------------------------------------------- */


	/**
	 * Installation helper method.
	 * 
	 * @access public
	 * @static
	 * @return void
	 */
	public static function db_create($destroy_first = TRUE)
	{
		if ($destroy_first)
			self::db_destroy();

		return get_instance()->db->query("
			CREATE TABLE IF NOT EXISTS `".self::$table_name."` (
				`id` int(10) unsigned NOT NULL AUTO_INCREMENT,
				`email` varchar(128) COLLATE utf8_unicode_ci DEFAULT NULL,
				`password` varchar(128) COLLATE utf8_unicode_ci DEFAULT NULL,
				`first_name` varchar(64) COLLATE utf8_unicode_ci DEFAULT NULL,
				`last_name` varchar(64) COLLATE utf8_unicode_ci DEFAULT NULL,
				`gender` varchar(16) COLLATE utf8_unicode_ci DEFAULT NULL,
				`created_at` datetime NOT NULL,
				`updated_at` datetime NOT NULL,
				PRIMARY KEY (`id`),
				UNIQUE KEY `email` (`email`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci AUTO_INCREMENT=1;
		");
	}


	/**
	 * Installation helper method.
	 * 
	 * @access private
	 * @static
	 * @return void
	 */
	private static function db_destroy()
	{
		return get_instance()->db->query("DROP TABLE IF EXISTS `".self::$table_name."`");
	}


	/* --------------------------------------------------
	 *	ACTIVERECORD CALLBACKS
	 * ----------------------------------------------- */


	/**
	 * Create a singular group for granular rules.
	 * 
	 * @access public
	 * @return void
	 */
	public function after_save()
	{
		// create and join a singular (i.e., granular) group
		$group = new Group();
		$group->name = $this->email;
		$group->singular = TRUE;
		$group->importance = 101;
		$group->save();
		
		$this->join_group($group);
		
		// join the global group
		$this->join_group(Group::find(1));
	}
}