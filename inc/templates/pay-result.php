<?php
/**
 * One screen for every result of a payment. States and their texts: bc_pay_result_copy() in inc/pay.php.
 * Used by thankyou.php (paid, placed, pending, fail, unknown), order-receipt.php (go, gateway) and, after a failed
 * payment, by checkout.php (fail).
 *
 * @var string        $state    paid | placed | unknown | pending | fail | gateway | go
 * @var WC_Order|null $order
 * @var string[]      $messages Reasons reported by the gateway.
 * @var string        $before   HTML plugins print before the order (woocommerce_before_thankyou).
 * @var string        $extra    HTML plugins print after it (woocommerce_thankyou_*): gateway notes, tracking scripts.
 */
defined( 'ABSPATH' ) || exit;

$order    = ( isset( $order ) && $order instanceof WC_Order ) ? $order : null;
$messages = isset( $messages ) ? (array) $messages : array();
$before   = isset( $before ) ? (string) $before : '';
$extra    = isset( $extra ) ? (string) $extra : '';
$copy     = bc_pay_result_copy( $state, $order );
$facts    = bc_pay_facts( $state, $order );
$ship     = $order ? bc_pay_shipping( $order ) : null;
$actions  = bc_pay_result_actions( $state, $order );
$contact  = bc_flow_contact();
$thanks   = in_array( $state, array( 'paid', 'placed', 'unknown', 'pending' ), true );
$can_pay  = $order && $order->needs_payment();
?>
<div class="bc-page bc-result bc-result--<?php echo esc_attr( $copy['tone'] ); ?>" data-bc-result="<?php echo esc_attr( $state ); ?>">
	<?php
	if ( $thanks ) {
		bc_flow_zone( 'thanks-top' );
	}
	?>
	<header class="bc-head"><p class="bc-head__title"><?php echo 'go' === $state ? 'پرداخت' : 'نتیجه پرداخت'; ?></p></header>
	<?php echo $before; // phpcs:ignore WordPress.Security.EscapeOutput ?>
	<div class="bc-notices" data-bc="notices"><?php wc_print_notices(); ?></div>

	<section class="bc-result__card" aria-labelledby="bc-result-title">
		<div class="bc-result__hero">
			<span class="bc-result__badge" aria-hidden="true"><span class="bc-result__mark"><?php echo 'go' === $state ? '<i class="bc-spin"></i>' : bc_icon( $copy['icon'], 30 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span></span>
			<h1 id="bc-result-title" class="bc-result__title"><?php echo esc_html( $copy['title'] ); ?></h1>
			<?php if ( '' !== $copy['text'] ) : ?>
				<p class="bc-result__sub"><?php echo esc_html( $copy['text'] ); ?></p>
			<?php endif; ?>
			<?php foreach ( $messages as $bc_msg ) : ?>
				<p class="bc-result__reason"><?php echo esc_html( $bc_msg ); ?></p>
			<?php endforeach; ?>
		</div>

		<?php if ( $facts ) : ?>
			<dl class="bc-facts">
				<?php foreach ( $facts as $bc_fact ) : ?>
					<div class="bc-facts__row<?php echo ! empty( $bc_fact['sep'] ) ? ' bc-facts__row--sep' : ''; ?>">
						<dt><?php echo esc_html( $bc_fact['label'] ); ?></dt>
						<dd>
							<?php if ( '' !== $bc_fact['copy'] ) : ?>
								<button type="button" class="bc-copy" data-bc-copy="<?php echo esc_attr( $bc_fact['copy'] ); ?>" aria-label="<?php echo esc_attr( 'کپی ' . $bc_fact['label'] ); ?>"><span dir="ltr"><?php echo $bc_fact['html']; // phpcs:ignore WordPress.Security.EscapeOutput ?></span><?php echo bc_icon( 'copy', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></button>
							<?php else : ?>
								<?php echo $bc_fact['html']; // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php endif; ?>
						</dd>
					</div>
				<?php endforeach; ?>
			</dl>
		<?php endif; ?>

		<?php if ( $ship && 'collect' === $ship['kind'] && in_array( $state, array( 'paid', 'placed' ), true ) ) : ?>
			<div class="bc-alert bc-alert--collect">
				<?php echo bc_icon( 'info', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<div><span><?php echo esc_html( bc_pay_collect_note( $ship ) ); ?></span></div>
			</div>
		<?php endif; ?>

		<?php if ( 'fail' === $state && $can_pay ) : ?>
			<div class="bc-alert bc-alert--info">
				<?php echo bc_icon( 'info', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<div><span>سفارش شماره <b><?php echo esc_html( $order->get_order_number() ); ?></b> هنوز نگه داشته شده است. می‌توانید دوباره یا با درگاه دیگری پرداخت کنید.</span></div>
			</div>
		<?php endif; ?>

		<?php echo $extra; // phpcs:ignore WordPress.Security.EscapeOutput ?>

		<?php if ( $order && in_array( $state, array( 'paid', 'placed', 'pending' ), true ) ) : ?>
			<details class="bc-more">
				<summary><span>اقلام و آدرس تحویل</span><?php echo bc_icon( 'down', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></summary>
				<div class="bc-more__body">
					<?php bc_pay_items( $order ); ?>
					<div class="bc-more__addr">
						<b>آدرس تحویل</b>
						<address><?php echo wp_kses_post( $order->get_formatted_billing_address() ); ?></address>
						<?php if ( $order->get_billing_phone() ) : ?>
							<span class="bc-muted">شماره تماس: <span dir="ltr"><?php echo esc_html( $order->get_billing_phone() ); ?></span></span>
						<?php endif; ?>
					</div>
				</div>
			</details>
		<?php endif; ?>

		<?php if ( $actions ) : ?>
			<div class="bc-result__actions">
				<?php
				foreach ( $actions as $bc_act ) {
					list( $bc_type, $bc_label, $bc_url, $bc_kind ) = $bc_act;
					$bc_form = ( 'form' === $bc_type && $order ) ? bc_pay_retry_form( $order, $bc_label ) : '';
					if ( '' !== $bc_form ) {
						echo $bc_form; // phpcs:ignore WordPress.Security.EscapeOutput
					} else {
						echo '<a class="bc-btn bc-btn--lg' . ( 'primary' === $bc_kind ? ' bc-btn--primary' : ' bc-btn--ghost' ) . '" href="' . esc_url( $bc_url ) . '">' . esc_html( $bc_label ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput
					}
				}
				?>
			</div>
		<?php endif; ?>

		<?php if ( in_array( $state, array( 'fail', 'gateway', 'pending' ), true ) && ! empty( $contact['phone'] ) ) : ?>
			<p class="bc-result__help">نیاز به کمک دارید؟
				<a class="bc-result__tel" href="tel:<?php echo esc_attr( $contact['phone'] ); ?>" dir="ltr"><?php echo bc_icon( 'phone', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?> <?php echo esc_html( $contact['phone'] ); ?></a>
			</p>
		<?php endif; ?>
	</section>

	<?php
	if ( $thanks ) {
		bc_flow_zone( 'thanks-bottom' );
	}
	?>
</div>