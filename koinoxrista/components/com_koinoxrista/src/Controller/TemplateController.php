<?php
namespace Koinoxrista\Component\Koinoxrista\Site\Controller;

\defined('_JEXEC') or die;

use Koinoxrista\Component\Koinoxrista\Site\Helper\Auth;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Db;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Layouts;
use Koinoxrista\Component\Koinoxrista\Site\Helper\Repo;

/**
 * Πρότυπα εκτύπωσης. Τα πρότυπα συστήματος (account_id=0) τα αλλάζει μόνο ο διαχειριστής
 * πλατφόρμας· οι συνδρομητές φτιάχνουν αντίγραφα και τα προσαρμόζουν στα δικά τους έντυπα.
 */
final class TemplateController extends Base
{
    public function create(): void
    {
        $this->guard();
        $account = $this->allowed();
        $type = array_key_exists($this->str('type'), Layouts::TYPES) ? $this->str('type') : 'sheet';
        $layout = $type === 'notice' ? Layouts::notice() : Layouts::sheet();
        $id = Db::insert('#__kx_templates', [
            'account_id' => (int) $account->id, 'title' => $this->str('title') ?: 'Νέο πρότυπο: ' . Layouts::TYPES[$type], 'type' => $type,
            'layout' => json_encode($layout, JSON_UNESCAPED_UNICODE), 'background' => '', 'is_default' => 0, 'created' => Db::now(), 'modified' => Db::now(),
        ]);
        $this->go('view=template&id=' . $id, 'Το πρότυπο δημιουργήθηκε από το βασικό σχέδιο. Προσαρμόστε το στο έντυπό σας.');
    }

    public function copy(): void
    {
        $this->guard();
        $account = $this->allowed();
        $t = Repo::template($this->int('id'));
        if (!$t) {
            $this->go('view=templates', 'Δεν βρέθηκε.', 'error');
            return;
        }
        $id = Db::insert('#__kx_templates', [
            'account_id' => (int) $account->id, 'title' => 'Αντίγραφο: ' . $t->title, 'type' => $t->type, 'layout' => $t->layout,
            'background' => $t->background, 'is_default' => 0, 'created' => Db::now(), 'modified' => Db::now(),
        ]);
        $this->go('view=template&id=' . $id, 'Δημιουργήθηκε αντίγραφο.');
    }

    public function save(): void
    {
        $this->guard();
        $t = $this->own($this->int('id'));
        $layout = Layouts::sanitize(json_decode((string) $this->input->post->get('layout', '', 'raw'), true));
        $data = ['title' => $this->str('title') ?: $t->title, 'layout' => json_encode($layout, JSON_UNESCAPED_UNICODE), 'modified' => Db::now()];
        try {
            $bg = $this->upload('background', 'a' . (int) $t->account_id . '/templates', 't' . (int) $t->id);
            if ($bg !== '') $data['background'] = $bg;
        } catch (\Throwable $e) {
            $this->app->enqueueMessage($e->getMessage(), 'error');
        }
        if ($this->int('remove_background')) $data['background'] = '';
        Db::update('#__kx_templates', (int) $t->id, $data);
        if ($this->int('ajax')) {
            echo json_encode(['ok' => true]);
            $this->app->close();
        }
        $this->go('view=template&id=' . (int) $t->id, 'Το πρότυπο αποθηκεύτηκε.');
    }

    public function setdefault(): void
    {
        $this->guard();
        $t = $this->own($this->int('id'));
        Db::exec('UPDATE #__kx_templates SET is_default=0 WHERE account_id=' . (int) $t->account_id . ' AND type=' . Db::q($t->type));
        Db::exec('UPDATE #__kx_templates SET is_default=1 WHERE id=' . (int) $t->id);
        $this->go('view=templates', '«' . $t->title . '» είναι πλέον το προεπιλεγμένο πρότυπο.');
    }

    public function delete(): void
    {
        $this->guard();
        $t = $this->own($this->int('id'));
        if ((int) $t->account_id === 0 && (int) Db::value('SELECT COUNT(*) FROM #__kx_templates WHERE account_id=0 AND type=' . Db::q($t->type)) <= 1) {
            $this->go('view=templates', 'Πρέπει να υπάρχει τουλάχιστον ένα πρότυπο συστήματος ανά τύπο.', 'warning');
            return;
        }
        Db::exec('DELETE FROM #__kx_templates WHERE id=' . (int) $t->id);
        if ($t->background && str_starts_with($t->background, 'images/koinoxrista/')) @unlink(JPATH_ROOT . '/' . $t->background);
        $this->go('view=templates', 'Το πρότυπο διαγράφηκε.');
    }

    private function allowed(): object
    {
        $account = Auth::requireWritable();
        Auth::requireFeature('designer');
        return $account;
    }

    /** Πρότυπο που μπορεί να αλλάξει ο τρέχων χρήστης. */
    private function own(int $id): object
    {
        $t = Db::one('SELECT * FROM #__kx_templates WHERE id=' . $id);
        $ok = $t && (((int) $t->account_id === 0 && Auth::isPlatformAdmin() && !Auth::acting()) || ((int) $t->account_id > 0 && (int) $t->account_id === Auth::accountId()));
        if (!$ok) {
            $this->go('view=templates', 'Τα πρότυπα συστήματος δεν αλλάζουν. Φτιάξτε ένα αντίγραφο.', 'warning');
            $this->app->close();
        }
        if ((int) $t->account_id > 0) $this->allowed();
        return $t;
    }
}
