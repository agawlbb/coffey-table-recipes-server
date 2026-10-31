"""Retired command: daily cleanup belongs to the VPS systemd timer."""


def schedule():
    raise RuntimeError('Cleanup moved to the VPS ctr-recipe-cleanup.timer; do not create an InMotion job')


if __name__ == '__main__':
    try:
        schedule()
    except RuntimeError as error:
        raise SystemExit(str(error)) from None
