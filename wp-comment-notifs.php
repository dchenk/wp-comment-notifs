<?php
/*
Plugin Name: Comment Notification
Plugin URI: https://github.com/dchenk/wp-comment-notifs
Description: Manage WordPress comment notifications.
Author: Wider Webs
Version: 1.0
Author URI: https://github.com/dchenk
Text Domain: wp_comment_notifs
*/

if (!defined('ABSPATH')) {
	exit;
}

if (!class_exists('WP_Comment_Notifs')) {
	class WP_Comment_Notifs {
		public function __construct() {
			add_filter('notify_moderator', [$this, 'remove_notifications'], 10, 2);
			add_action('comment_post', [$this, 'init_notifications'], 999, 2);
			add_action('admin_menu', [$this, 'admin_menu']);
			add_action('admin_init', [$this, 'wp_comment_notification_settings_init']);
		}

		public function admin_menu() {
			add_options_page(
				__('Comment Notifs', 'wp_comment_notifs'),
				__('Comment Notifs', 'wp_comment_notifs'),
				'manage_options',
				'wp-comment-notifs',
				[$this, 'settings_page']
			);
		}

		public function wp_comment_notification_settings_init() {
			register_setting('wp_comment_notification', 'wp_comment_notification_settings');

			add_settings_section(
				'wp_comment_notification_section',
				'',
				[$this, 'wp_comment_notification_settings_section_callback'],
				'wp_comment_notification'
			);

			add_settings_field(
				'wp_comment_notification_emails',
				__('Notification email id(s)', 'wp_comment_notifs'),
				[$this, 'wp_comment_notification_textarea_render'],
				'wp_comment_notification',
				'wp_comment_notification_section'
			);

			add_settings_field(
				'wp_comment_notification_author',
				__('Notify post author', 'wp_comment_notifs'),
				[$this, 'wp_comment_notification_checkbox_render'],
				'wp_comment_notification',
				'wp_comment_notification_section'
			);
		}

		public function wp_comment_notification_textarea_render() {
			$wpcn_mails = $this->get_notification_mail_ids(); ?>
			<textarea cols='60' rows='10' name='wp_comment_notification_settings[wp_comment_notification_emails]'><?php echo $wpcn_mails; ?></textarea>
			<br><span>Comma-separated emails</span>
			<?php
		}

		public function wp_comment_notification_checkbox_render() {
			$notify_author = !empty(get_option('wp_comment_notification_settings')['wp_comment_notification_author']); ?>
			<input type='checkbox' name='wp_comment_notification_settings[wp_comment_notification_author]' <?php checked($notify_author); ?> value='1'>
			<?php
		}

		public function wp_comment_notification_settings_section_callback() {
			echo __('Comment Notification Setting', 'wp_comment_notifs');
		}

		public function settings_page() {
			?>
			<form action='options.php' method='post'>
				<h2><?php echo __('WP Comment Notification', 'wp_comment_notifs'); ?></h2>
				<?php
			settings_fields('wp_comment_notification');
			do_settings_sections('wp_comment_notification');
			submit_button(); ?>
			</form>
			<?php
		}

		public function get_default_notification_mails() {
			$emails = [get_option('admin_email')];
			$blogusers = get_users('role=administrator');
			if (is_array($blogusers) && count($blogusers)>0) {
				foreach ($blogusers as $admin_user) {
					if (0 !== strcasecmp($admin_user->data->user_email, get_option('admin_email'))) {
						$emails[] = $admin_user->data->user_email;
					}
				}
			}
			return $emails;
		}

		public function get_notification_mail_ids() {
			$options = get_option('wp_comment_notification_settings');
			return $options['wp_comment_notification_emails'] ??
				implode(',', $this->get_default_notification_mails());
		}

		public function remove_notifications($maybe_notify, $comment_ID): bool {
			return false;
		}

		public function init_notifications($comment_id, $commentdata): bool {
			global $wpdb;

			$comment = get_comment($comment_id);
			if ($comment->comment_approved != '0') {
				return false;
			}

			$post = get_post($comment->comment_post_ID);
			$options = get_option('wp_comment_notification_settings');

			// Add Admin Emails
			$emails = explode(',', $this->get_notification_mail_ids());

			// Add author's email.
			if (!empty($options['wp_comment_notification_author'])) {
				$author = get_userdata($post->post_author);
				if ($author) {
					$emails[] = $author->user_email;
				}
			}

			if (empty($comment) || empty($comment->comment_post_ID) || count($emails) == 0) {
				return false;
			}

			if (is_array($emails) && count($emails)>0) {
				$emails = array_unique($emails);
			}

			if (function_exists('switch_to_locale')) {
				$switched_locale = switch_to_locale(get_locale());
			}
			$comment_author_domain = @gethostbyaddr($comment->comment_author_IP);
			$comments_waiting = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = '0'");

			// The blogname option is escaped with esc_html on the way into the database in sanitize_option
			// we want to reverse this for the plain text arena of emails.
			$blogname = wp_specialchars_decode(get_option('blogname'), ENT_QUOTES);
			$comment_content = wp_specialchars_decode($comment->comment_content);

			switch ($comment->comment_type) {
			case 'trackback':
				// translators: 1: Post title
				$notify_message  = sprintf(__('A new trackback on the post "%s" is waiting for your approval'), $post->post_title) . "\r\n";
				$notify_message .= get_permalink($comment->comment_post_ID) . "\r\n\r\n";
				// translators: 1: Trackback/pingback website name, 2: website IP, 3: website hostname
				$notify_message .= sprintf(__('Website: %1$s (IP: %2$s, %3$s)'), $comment->comment_author, $comment->comment_author_IP, $comment_author_domain) . "\r\n";
				// translators: 1: Trackback/pingback/comment author URL
				$notify_message .= sprintf(__('URL: %s'), $comment->comment_author_url) . "\r\n\r\n";
				$notify_message .= __('Trackback excerpt: ') . "\r\n" . $comment_content . "\r\n\r\n";
				break;
			case 'pingback':
				// translators: 1: Post title
				$notify_message  = sprintf(__('A new pingback on the post "%s" is waiting for your approval'), $post->post_title) . "\r\n";
				$notify_message .= get_permalink($comment->comment_post_ID) . "\r\n\r\n";
				// translators: 1: Trackback/pingback website name, 2: website IP, 3: website hostname
				$notify_message .= sprintf(__('Website: %1$s (IP: %2$s, %3$s)'), $comment->comment_author, $comment->comment_author_IP, $comment_author_domain) . "\r\n";
				// translators: 1: Trackback/pingback/comment author URL
				$notify_message .= sprintf(__('URL: %s'), $comment->comment_author_url) . "\r\n\r\n";
				$notify_message .= __('Pingback excerpt: ') . "\r\n" . $comment_content . "\r\n\r\n";
				break;
			default: // Comments
				// translators: 1: Post title
				$notify_message  = sprintf(__('A new comment on the post "%s" is waiting for your approval:'), $post->post_title) . "\r\n";
				$notify_message .= get_permalink($comment->comment_post_ID) . "\r\n\r\n";
				// translators: 1: Comment author name, 2: comment author's IP, 3: comment author IP's hostname
				$notify_message .= sprintf(__('Author: %1$s (IP: %2$s, %3$s)'), $comment->comment_author, $comment->comment_author_IP, $comment_author_domain) . "\r\n";
				// translators: 1: Comment author URL
				$notify_message .= sprintf(__('Email: %s'), $comment->comment_author_email) . "\r\n\r\n";
				// translators: 1: Trackback/pingback/comment author URL
				$notify_message .= sprintf(__('URL: %s'), $comment->comment_author_url) . "\r\n\r\n";
				// translators: 1: Comment text
				$notify_message .= sprintf(__('Comment: %s'), "\r\n" . $comment_content) . "\r\n\r\n";
			}

			// translators: Comment moderation. 1: Comment action URL
			$notify_message .= sprintf(__('Approve it: %s'), admin_url("comment.php?action=approve&c={$comment_id}#wpbody-content")) . "\r\n";

			if (defined('EMPTY_TRASH_DAYS') && EMPTY_TRASH_DAYS) {
				// translators: Comment moderation. 1: Comment action URL
				$notify_message .= sprintf(__('Trash it: %s'), admin_url("comment.php?action=trash&c={$comment_id}#wpbody-content")) . "\r\n";
			} else {
				// translators: Comment moderation. 1: Comment action URL
				$notify_message .= sprintf(__('Delete it: %s'), admin_url("comment.php?action=delete&c={$comment_id}#wpbody-content")) . "\r\n";
			}

			// translators: Comment moderation. 1: Comment action URL
			$notify_message .= sprintf(__('Spam it: %s'), admin_url("comment.php?action=spam&c={$comment_id}#wpbody-content")) . "\r\n";

			// translators: Comment moderation. 1: Number of comments awaiting approval
			$notify_message .= sprintf(_n(
				'Currently %s comment is waiting for approval. Please visit the moderation panel:',
					'Currently %s comments are waiting for approval. Please visit the moderation panel:',
				$comments_waiting
			), number_format_i18n($comments_waiting)) . "\r\n";
			$notify_message .= admin_url("edit-comments.php?comment_status=moderated#wpbody-content") . "\r\n";

			// translators: Comment moderation notification email subject. 1: Site name, 2: Post title
			$subject = sprintf(__('[%1$s] Please moderate: "%2$s"'), $blogname, $post->post_title);
			$message_headers = '';

			/**
			 * Filters the list of recipients for comment moderation emails.
			 *
			 * @param array $emails	 List of email addresses to notify for comment moderation.
			 * @param int   $comment_id Comment ID.
			 */
			$emails = apply_filters('wp_comment_notification_recipients', $emails, $comment_id);

			/**
			 * Filters the comment moderation email text.
			 *
			 * @param string $notify_message Text of the comment moderation email.
			 * @param int	$comment_id	 Comment ID.
			 */
			$notify_message = apply_filters('comment_moderation_text', $notify_message, $comment_id);

			/**
			 * Filters the comment moderation email subject.
			 *
			 * @param string $subject	Subject of the comment moderation email.
			 * @param int	$comment_id Comment ID.
			 */
			$subject = apply_filters('comment_moderation_subject', $subject, $comment_id);

			/**
			 * Filters the comment moderation email headers.
			 *
			 * @param string $message_headers Headers for the comment moderation email.
			 * @param int $comment_id Comment ID.
			 */
			$message_headers = apply_filters('comment_moderation_headers', $message_headers, $comment_id);

			foreach ($emails as $email) {
				@wp_mail($email, wp_specialchars_decode($subject), $notify_message, $message_headers);
			}

			if (function_exists('restore_previous_locale') && !empty($switched_locale)) {
				restore_previous_locale();
			}

			return true;
		}
	}
}

new WP_Comment_Notifs();

