<?php
namespace Koinoxrista\Component\Koinoxrista\Site\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Mail\MailerFactoryInterface;

/** Αποστολή email μέσω του mailer του Joomla (ρυθμίσεις SMTP του site). */
final class Mail
{
    public static function send(string $to, string $subject, string $html, string $replyTo = ''): bool
    {
        try {
            $mailer = Factory::getContainer()->get(MailerFactoryInterface::class)->createMailer();
            $mailer->addRecipient($to);
            if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
                $mailer->addReplyTo($replyTo);
            }
            $mailer->setSubject($subject);
            $mailer->isHtml(true);
            $mailer->setBody(self::wrap($html));
            return (bool) $mailer->Send();
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function wrap(string $html): string
    {
        $color = Ui::color((string) Settings::get('brand_color'));
        return '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#1f2937;line-height:1.55;max-width:560px">'
            . '<div style="border-top:4px solid ' . $color . ';padding-top:14px;font-weight:700;font-size:17px">' . Fmt::e((string) Settings::get('brand_name')) . '</div>'
            . '<div style="padding:12px 0">' . $html . '</div></div>';
    }
}
