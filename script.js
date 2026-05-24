// RCCG Dominion Centre — Interactive Scripts

document.addEventListener('DOMContentLoaded', function () {

    const hamburger = document.querySelector('.hamburger');
    const navMenu = document.querySelector('.nav-menu');
    const navLinks = document.querySelectorAll('.nav-link, .btn-nav-cta');
    const header = document.querySelector('.site-header');

    if (hamburger && navMenu) {
        hamburger.addEventListener('click', function () {
            hamburger.classList.toggle('active');
            navMenu.classList.toggle('active');
            document.body.style.overflow = navMenu.classList.contains('active') ? 'hidden' : '';
        });

        navLinks.forEach(link => {
            link.addEventListener('click', function () {
                hamburger.classList.remove('active');
                navMenu.classList.remove('active');
                document.body.style.overflow = '';
            });
        });

        document.addEventListener('click', function (e) {
            if (!hamburger.contains(e.target) && !navMenu.contains(e.target)) {
                hamburger.classList.remove('active');
                navMenu.classList.remove('active');
                document.body.style.overflow = '';
            }
        });
    }

    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            const href = this.getAttribute('href');
            if (href && href.length > 1) {
                const target = document.querySelector(href);
                if (target) {
                    e.preventDefault();
                    const offset = header ? header.offsetHeight : 80;
                    const top = target.getBoundingClientRect().top + window.pageYOffset - offset;
                    window.scrollTo({ top, behavior: 'smooth' });
                }
            }
        });
    });

    if (header) {
        const isTransparent = header.classList.contains('site-header--transparent');
        window.addEventListener('scroll', function () {
            if (window.pageYOffset > 60) {
                header.classList.add('scrolled');
            } else if (isTransparent) {
                header.classList.remove('scrolled');
            }
        }, { passive: true });
    }

    const scrollObserver = new IntersectionObserver(function (entries) {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('is-visible');
                scrollObserver.unobserve(entry.target);
            }
        });
    }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });

    document.querySelectorAll('.animate-on-scroll').forEach(el => scrollObserver.observe(el));

    const legacyAnimate = document.querySelectorAll('.service-card, .event-card, .about-text, .about-image, .cta-card');
    const legacyObserver = new IntersectionObserver(function (entries) {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('animate-fade-in');
                legacyObserver.unobserve(entry.target);
            }
        });
    }, { threshold: 0.1 });

    legacyAnimate.forEach(el => {
        if (!el.classList.contains('animate-on-scroll')) {
            el.style.opacity = '0';
            el.style.transform = 'translateY(24px)';
            el.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
            legacyObserver.observe(el);
        }
    });

    if (!document.getElementById('fade-in-style')) {
        const style = document.createElement('style');
        style.id = 'fade-in-style';
        style.textContent = '.animate-fade-in { opacity: 1 !important; transform: translateY(0) !important; }';
        document.head.appendChild(style);
    }

    const currentPage = window.location.pathname.split('/').pop().replace('.html', '') || 'index';
    navLinks.forEach(link => {
        const href = (link.getAttribute('href') || '').replace('.html', '');
        link.classList.toggle('active', href === currentPage || (currentPage === '' && href === 'index'));
    });

    const contactForm = document.getElementById('contact-form');
    if (contactForm) {
        contactForm.addEventListener('submit', function (e) {
            e.preventDefault();
            let isValid = true;
            document.querySelectorAll('.error-message').forEach(el => el.remove());
            document.querySelectorAll('.input-error').forEach(el => el.classList.remove('input-error'));

            ['name', 'email', 'subject', 'message'].forEach(id => {
                const field = document.getElementById(id);
                if (field && field.value.trim() === '') {
                    showError(field, 'This field is required');
                    isValid = false;
                }
            });

            const email = document.getElementById('email');
            if (email && email.value && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value)) {
                showError(email, 'Please enter a valid email');
                isValid = false;
            }

            if (isValid) {
                showSuccessMessage('Thank you. Your message has been sent successfully.');
                contactForm.reset();
            }
        });
    }

    const volunteerForm = document.getElementById('volunteer-form');
    if (volunteerForm) {
        volunteerForm.addEventListener('submit', function (e) {
            e.preventDefault();
            showSuccessMessage('Thank you. Your volunteer application has been received.');
            volunteerForm.reset();
        });
    }

    function showError(input, message) {
        input.classList.add('input-error');
        const errorDiv = document.createElement('div');
        errorDiv.className = 'error-message';
        errorDiv.textContent = message;
        input.parentElement.appendChild(errorDiv);
    }

    function showSuccessMessage(text) {
        const successDiv = document.createElement('div');
        successDiv.className = 'success-message';
        successDiv.innerHTML = '<span class="icon icon--check" aria-hidden="true"></span> ' + text;
        document.body.appendChild(successDiv);
        setTimeout(() => successDiv.remove(), 4000);
    }

    const filterButtons = document.querySelectorAll('.event-filters .filter-btn');
    const eventItems = document.querySelectorAll('.event-item, .event-card-detailed');
    if (filterButtons.length && eventItems.length) {
        filterButtons.forEach(button => {
            button.addEventListener('click', function () {
                filterButtons.forEach(btn => btn.classList.remove('active'));
                this.classList.add('active');
                const filter = this.getAttribute('data-filter');
                eventItems.forEach(card => {
                    const categories = (card.getAttribute('data-category') || '').split(/\s+/);
                    const show = filter === 'all' || categories.includes(filter);
                    card.style.display = show ? '' : 'none';
                });
            });
        });
    }

    document.querySelectorAll('.faq-question').forEach(question => {
        question.addEventListener('click', function () {
            this.closest('.faq-item').classList.toggle('active');
        });
    });

    const backToTop = document.createElement('button');
    backToTop.className = 'back-to-top';
    backToTop.setAttribute('aria-label', 'Back to top');
    backToTop.innerHTML = '<span class="icon icon--arrow-up" aria-hidden="true"></span>';
    document.body.appendChild(backToTop);

    window.addEventListener('scroll', function () {
        backToTop.classList.toggle('show', window.pageYOffset > 400);
    }, { passive: true });

    backToTop.addEventListener('click', function () {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });

    window.copyToClipboard = function (text) {
        navigator.clipboard.writeText(text).then(function () {
            showSuccessMessage('Copied to clipboard.');
        });
    };
});

window.addEventListener('load', function () {
    const loader = document.getElementById('pageLoader');
    if (loader) {
        setTimeout(function () {
            loader.classList.add('loaded');
            setTimeout(function () { loader.style.display = 'none'; }, 600);
        }, 400);
    }
});
