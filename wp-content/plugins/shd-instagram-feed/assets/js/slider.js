/**
 * SHD Instagram Feed – slider controls.
 *
 * The slider already works without this script: the track is a native
 * scroll-snap container, so touch and trackpad swiping need no JavaScript.
 * This adds arrows, the position indicator, autoplay with a pause button,
 * pausing on hover / focus / when off-screen, and keyboard support.
 * No dependencies.
 */
( function () {
	'use strict';

	var MAX_DOTS = 15;
	var instances = typeof WeakMap === 'function' ? new WeakMap() : null;
	var motionQuery = window.matchMedia ? window.matchMedia( '(prefers-reduced-motion: reduce)' ) : null;

	function reducedMotion() {
		return !! ( motionQuery && motionQuery.matches );
	}

	function format( template, value ) {
		return String( template ).replace( '%d', value );
	}

	function Slider( root ) {
		var config = {};
		var speed;

		try {
			config = JSON.parse( root.getAttribute( 'data-shd-igf' ) || '{}' ) || {};
		} catch ( e ) {
			config = {};
		}

		speed = parseInt( config.speed, 10 );

		this.root = root;
		this.track = root.querySelector( '.shd-igf__track' );
		this.slides = this.track ? Array.prototype.slice.call( this.track.children ) : [];
		this.prevButton = root.querySelector( '.shd-igf__arrow--prev' );
		this.nextButton = root.querySelector( '.shd-igf__arrow--next' );
		this.pauseButton = root.querySelector( '.shd-igf__pause' );
		this.dotsContainer = root.querySelector( '.shd-igf__dots' );
		this.fraction = root.querySelector( '.shd-igf__fraction' );
		this.progress = root.querySelector( '.shd-igf__progress' );
		this.i18n = config.i18n || {};
		this.config = {
			autoplay: !! config.autoplay && ! document.body.classList.contains( 'elementor-editor-active' ),
			delay: Math.max( 1000, parseInt( config.delay, 10 ) || 6000 ),
			pauseOnHover: config.pauseOnHover !== false,
			loop: config.loop !== false,
			speed: isNaN( speed ) ? 600 : Math.max( 0, speed ),
			scrollBy: config.scrollBy === 'page' ? 'page' : 'slide'
		};

		this.index = 0;
		this.target = null;
		this.positions = 1;
		this.perView = 1;
		this.step = 0;
		this.maxScroll = 0;
		this.stops = [ 0 ];
		this.dots = null;
		this.dotsKey = '';
		this.autoFraction = null;
		this.rtl = false;
		this.timer = null;
		this.frame = null;
		this.animation = null;
		this.userPaused = reducedMotion();
		this.hovered = false;
		this.focused = false;
		this.visible = true;

		if ( ! this.track || ! this.slides.length ) {
			return;
		}

		this.bind();
		this.measure();
		this.root.classList.add( 'is-ready' );
		this.schedule();
	}

	Slider.prototype.bind = function () {
		var self = this;
		var root = this.root;
		var track = this.track;

		if ( this.prevButton ) {
			this.prevButton.addEventListener( 'click', function () {
				if ( self.prevButton.getAttribute( 'aria-disabled' ) !== 'true' ) {
					self.move( -1 );
				}
			} );
		}

		if ( this.nextButton ) {
			this.nextButton.addEventListener( 'click', function () {
				if ( self.nextButton.getAttribute( 'aria-disabled' ) !== 'true' ) {
					self.move( 1 );
				}
			} );
		}

		if ( this.pauseButton ) {
			this.pauseButton.addEventListener( 'click', function () {
				self.userPaused = ! self.userPaused;
				self.schedule();
			} );
		}

		if ( this.dotsContainer ) {
			this.dotsContainer.addEventListener( 'click', function ( event ) {
				var dot = event.target.closest ? event.target.closest( '.shd-igf__dot' ) : null;
				if ( dot ) {
					self.goTo( self.stops[ parseInt( dot.getAttribute( 'data-stop' ), 10 ) ] || 0 );
				}
			} );
		}

		// Keep indicators in sync with native scrolling (swipe, trackpad).
		track.addEventListener( 'scroll', function () {
			if ( self.frame ) {
				return;
			}
			self.frame = window.requestAnimationFrame( function () {
				self.frame = null;
				if ( ! self.animation ) {
					self.update();
					self.schedule();
				}
			} );
		}, { passive: true } );

		// Any direct interaction with the track cancels a running animation.
		[ 'pointerdown', 'wheel', 'touchstart' ].forEach( function ( type ) {
			track.addEventListener( type, function () {
				if ( self.animation ) {
					self.stopAnimation();
				}
			}, { passive: true } );
		} );

		root.addEventListener( 'keydown', function ( event ) {
			self.onKeydown( event );
		} );

		root.addEventListener( 'focusin', function () {
			self.focused = true;
			self.schedule();
		} );

		root.addEventListener( 'focusout', function ( event ) {
			if ( ! event.relatedTarget || ! root.contains( event.relatedTarget ) ) {
				self.focused = false;
				self.schedule();
			}
		} );

		if ( this.config.pauseOnHover ) {
			root.addEventListener( 'pointerenter', function ( event ) {
				if ( event.pointerType === 'mouse' ) {
					self.hovered = true;
					self.schedule();
				}
			} );
			root.addEventListener( 'pointerleave', function ( event ) {
				if ( event.pointerType === 'mouse' ) {
					self.hovered = false;
					self.schedule();
				}
			} );
		}

		// Only autoplay while at least 30% of the slider is on screen.
		if ( 'IntersectionObserver' in window ) {
			this.observer = new IntersectionObserver( function ( entries ) {
				var entry = entries[ entries.length - 1 ];
				self.visible = entry.isIntersecting && entry.intersectionRatio >= 0.29;
				self.schedule();
			}, { threshold: [ 0, 0.3 ] } );
			this.observer.observe( root );
		}

		if ( 'ResizeObserver' in window ) {
			this.resizer = new ResizeObserver( function () {
				self.measure();
			} );
			this.resizer.observe( track );
		} else {
			this.onResize = function () {
				self.measure();
			};
			window.addEventListener( 'resize', this.onResize );
		}

		this.onVisibility = function () {
			self.schedule();
		};
		document.addEventListener( 'visibilitychange', this.onVisibility );

		if ( motionQuery ) {
			this.onMotion = function () {
				if ( reducedMotion() ) {
					self.userPaused = true;
				}
				self.schedule();
			};
			if ( motionQuery.addEventListener ) {
				motionQuery.addEventListener( 'change', this.onMotion );
			} else if ( motionQuery.addListener ) {
				motionQuery.addListener( this.onMotion );
			}
		}
	};

	/**
	 * Read the layout: slide step, posts per view, number of positions.
	 */
	Slider.prototype.measure = function () {
		var track = this.track;
		var first = this.slides[ 0 ].getBoundingClientRect();
		var gap = 0;

		if ( this.slides.length > 1 ) {
			gap = Math.abs( this.slides[ 1 ].getBoundingClientRect().left - first.left ) - first.width;
		}
		gap = Math.max( 0, gap );

		this.rtl = window.getComputedStyle( track ).direction === 'rtl';
		this.step = first.width + gap;
		this.maxScroll = Math.max( 0, track.scrollWidth - track.clientWidth );
		this.perView = this.step > 0 ? Math.max( 1, Math.round( ( track.clientWidth + gap ) / this.step ) ) : 1;
		this.positions = this.maxScroll > 1 ? Math.max( 2, this.slides.length - this.perView + 1 ) : 1;
		this.stops = this.computeStops();

		this.root.classList.toggle( 'is-static', this.positions < 2 );
		this.renderDots();
		this.update();
		this.schedule();
	};

	/**
	 * Positions the dots jump to: every position, or every page.
	 */
	Slider.prototype.computeStops = function () {
		var last = this.positions - 1;
		var amount = this.config.scrollBy === 'page' ? this.perView : 1;
		var stops = [];
		var i;

		for ( i = 0; i < last; i += amount ) {
			stops.push( i );
		}
		stops.push( last );
		return stops;
	};

	Slider.prototype.activeStop = function ( index ) {
		var active = 0;
		var i;

		for ( i = 0; i < this.stops.length; i++ ) {
			if ( this.stops[ i ] <= index ) {
				active = i;
			}
		}
		return active;
	};

	Slider.prototype.renderDots = function () {
		var container = this.dotsContainer;
		var count = this.stops.length;
		var key = count + ':' + this.perView;
		var fragment;
		var i;
		var dot;

		if ( ! container || key === this.dotsKey ) {
			return;
		}

		this.dotsKey = key;
		this.dots = null;
		this.autoFraction = null;

		// Keep the server-rendered dots (or counter) when they already match.
		if ( count > MAX_DOTS && container.children.length === 1 && container.firstElementChild.classList.contains( 'shd-igf__fraction' ) ) {
			this.autoFraction = container.firstElementChild;
			return;
		}
		if ( count >= 2 && count <= MAX_DOTS && container.querySelectorAll( '.shd-igf__dot' ).length === count && container.children.length === count ) {
			this.dots = Array.prototype.slice.call( container.children );
			return;
		}

		container.textContent = '';

		if ( count < 2 ) {
			return;
		}

		// Too many positions for dots: show a counter instead.
		if ( count > MAX_DOTS ) {
			this.autoFraction = document.createElement( 'span' );
			this.autoFraction.className = 'shd-igf__fraction';
			this.autoFraction.setAttribute( 'aria-hidden', 'true' );
			container.appendChild( this.autoFraction );
			return;
		}

		fragment = document.createDocumentFragment();
		this.dots = [];

		for ( i = 0; i < count; i++ ) {
			dot = document.createElement( 'button' );
			dot.type = 'button';
			dot.className = 'shd-igf__dot';
			dot.setAttribute( 'data-stop', String( i ) );
			dot.setAttribute( 'aria-controls', this.track.id );
			dot.setAttribute( 'aria-label', format( this.i18n.goTo || 'Show position %d', i + 1 ) );
			fragment.appendChild( dot );
			this.dots.push( dot );
		}

		container.appendChild( fragment );
	};

	Slider.prototype.currentIndex = function () {
		var x;

		if ( this.target !== null ) {
			return this.target;
		}
		if ( this.positions < 2 || this.step <= 0 ) {
			return 0;
		}

		x = Math.abs( this.track.scrollLeft );
		if ( x >= this.maxScroll - 2 ) {
			return this.positions - 1;
		}
		return Math.max( 0, Math.min( this.positions - 1, Math.round( x / this.step ) ) );
	};

	/**
	 * Update arrows, dots, counter and progress for a position.
	 *
	 * @param {number} [index] Position; read from the scroll offset when omitted.
	 */
	Slider.prototype.update = function ( index ) {
		var last = this.positions - 1;
		var total = this.slides.length;
		var visibleEnd;
		var active;
		var i;

		if ( typeof index !== 'number' ) {
			index = this.currentIndex();
		}
		this.index = index;
		visibleEnd = Math.min( total, index + this.perView );

		if ( this.prevButton ) {
			this.setDisabled( this.prevButton, ! this.config.loop && index <= 0 );
		}
		if ( this.nextButton ) {
			this.setDisabled( this.nextButton, ! this.config.loop && index >= last );
		}

		if ( this.dots ) {
			active = this.activeStop( index );
			for ( i = 0; i < this.dots.length; i++ ) {
				if ( i === active ) {
					this.dots[ i ].setAttribute( 'aria-current', 'true' );
					this.dots[ i ].tabIndex = 0;
				} else {
					this.dots[ i ].removeAttribute( 'aria-current' );
					this.dots[ i ].tabIndex = -1;
				}
			}
		}

		if ( this.fraction ) {
			this.fraction.textContent = visibleEnd + ' / ' + total;
		}
		if ( this.autoFraction ) {
			this.autoFraction.textContent = visibleEnd + ' / ' + total;
		}
		if ( this.progress ) {
			this.progress.style.setProperty( '--shd-igf-progress', ( visibleEnd / total ).toFixed( 4 ) );
		}
	};

	Slider.prototype.setDisabled = function ( button, disabled ) {
		if ( disabled ) {
			button.setAttribute( 'aria-disabled', 'true' );
		} else {
			button.removeAttribute( 'aria-disabled' );
		}
	};

	/**
	 * Move forward (1) or back (-1) by one post or one page.
	 *
	 * @param {number}  direction    1 or -1.
	 * @param {boolean} fromAutoplay Whether autoplay triggered the move.
	 */
	Slider.prototype.move = function ( direction, fromAutoplay ) {
		var current = this.currentIndex();
		var last = this.positions - 1;
		var amount = this.config.scrollBy === 'page' ? this.perView : 1;
		var index;

		if ( last < 1 ) {
			return;
		}

		if ( direction > 0 ) {
			index = current >= last ? ( this.config.loop ? 0 : last ) : Math.min( last, current + amount );
		} else {
			index = current <= 0 ? ( this.config.loop ? last : 0 ) : Math.max( 0, current - amount );
		}

		this.goTo( index, fromAutoplay );
	};

	Slider.prototype.goTo = function ( index, fromAutoplay ) {
		var offset;

		index = Math.max( 0, Math.min( this.positions - 1, index ) );
		offset = index >= this.positions - 1 ? this.maxScroll : Math.min( index * this.step, this.maxScroll );

		this.scrollTo( this.rtl ? -offset : offset, index );
		this.update( index );

		if ( ! fromAutoplay ) {
			this.schedule();
		}
	};

	/**
	 * Animate scrollLeft with an ease-in-out curve. Snapping is switched off
	 * during the animation and resumes at the (snap-aligned) end position.
	 *
	 * @param {number} x     Target scrollLeft.
	 * @param {number} index Target position.
	 */
	Slider.prototype.scrollTo = function ( x, index ) {
		var self = this;
		var track = this.track;
		var start = track.scrollLeft;
		var distance = x - start;
		var duration = reducedMotion() ? 0 : this.config.speed;
		var startTime = null;

		this.stopAnimation();

		if ( ! duration || Math.abs( distance ) < 1 ) {
			track.scrollLeft = x;
			return;
		}

		this.target = index;
		track.classList.add( 'is-animating' );

		function frame( now ) {
			var progress;
			var eased;

			if ( startTime === null ) {
				startTime = now;
			}
			progress = Math.min( 1, ( now - startTime ) / duration );
			eased = progress < 0.5 ? 4 * progress * progress * progress : 1 - Math.pow( -2 * progress + 2, 3 ) / 2;
			track.scrollLeft = start + distance * eased;

			if ( progress < 1 ) {
				self.animation = window.requestAnimationFrame( frame );
			} else {
				self.animation = null;
				self.target = null;
				track.classList.remove( 'is-animating' );
				self.update();
			}
		}

		this.animation = window.requestAnimationFrame( frame );
	};

	Slider.prototype.stopAnimation = function () {
		if ( this.animation ) {
			window.cancelAnimationFrame( this.animation );
			this.animation = null;
		}
		this.target = null;
		this.track.classList.remove( 'is-animating' );
	};

	Slider.prototype.onKeydown = function ( event ) {
		var active = document.activeElement;
		var direction = 0;
		var target;

		if ( event.defaultPrevented || event.altKey || event.ctrlKey || event.metaKey || this.positions < 2 ) {
			return;
		}

		if ( event.key === 'ArrowRight' ) {
			direction = this.rtl ? -1 : 1;
		} else if ( event.key === 'ArrowLeft' ) {
			direction = this.rtl ? 1 : -1;
		} else if ( event.key === 'Home' && this.dots && active && active.classList.contains( 'shd-igf__dot' ) ) {
			event.preventDefault();
			this.goTo( 0 );
			this.dots[ 0 ].focus();
			return;
		} else if ( event.key === 'End' && this.dots && active && active.classList.contains( 'shd-igf__dot' ) ) {
			event.preventDefault();
			this.goTo( this.positions - 1 );
			this.dots[ this.dots.length - 1 ].focus();
			return;
		}

		if ( ! direction ) {
			return;
		}

		event.preventDefault();
		this.move( direction );

		// Keep keyboard focus on something visible.
		if ( active && active.classList.contains( 'shd-igf__dot' ) && this.dots ) {
			this.dots[ this.activeStop( this.index ) ].focus();
		} else if ( active && active.classList.contains( 'shd-igf__link' ) ) {
			target = this.slides[ this.index ] && this.slides[ this.index ].querySelector( '.shd-igf__link' );
			if ( target ) {
				target.focus( { preventScroll: true } );
			}
		}
	};

	Slider.prototype.syncPauseButton = function () {
		var button = this.pauseButton;

		if ( ! button ) {
			return;
		}
		button.classList.toggle( 'is-paused', this.userPaused );
		button.setAttribute( 'aria-label', this.userPaused ? ( this.i18n.play || 'Start automatic scrolling' ) : ( this.i18n.pause || 'Pause automatic scrolling' ) );
	};

	/**
	 * (Re)start the autoplay timer if autoplay may run right now.
	 */
	Slider.prototype.schedule = function () {
		var self = this;

		window.clearTimeout( this.timer );
		this.timer = null;
		this.syncPauseButton();

		if ( ! this.root.isConnected ) {
			this.destroy();
			return;
		}

		if ( ! this.config.autoplay || this.userPaused || this.hovered || this.focused || ! this.visible || document.hidden || this.positions < 2 ) {
			return;
		}

		this.timer = window.setTimeout( function () {
			self.timer = null;
			if ( ! self.root.isConnected ) {
				self.destroy();
				return;
			}
			self.move( 1, true );
			self.schedule();
		}, this.config.delay );
	};

	Slider.prototype.destroy = function () {
		window.clearTimeout( this.timer );
		this.stopAnimation();
		if ( this.observer ) {
			this.observer.disconnect();
		}
		if ( this.resizer ) {
			this.resizer.disconnect();
		}
		if ( this.onResize ) {
			window.removeEventListener( 'resize', this.onResize );
		}
		document.removeEventListener( 'visibilitychange', this.onVisibility );
		if ( motionQuery && this.onMotion ) {
			if ( motionQuery.removeEventListener ) {
				motionQuery.removeEventListener( 'change', this.onMotion );
			} else if ( motionQuery.removeListener ) {
				motionQuery.removeListener( this.onMotion );
			}
		}
		if ( instances ) {
			instances.delete( this.root );
		} else {
			this.root.shdIgf = null;
		}
	};

	function init( root ) {
		if ( ! root || ( instances ? instances.has( root ) : root.shdIgf ) ) {
			return;
		}
		var slider = new Slider( root );
		if ( instances ) {
			instances.set( root, slider );
		} else {
			root.shdIgf = slider;
		}
	}

	function initAll( scope ) {
		var context = scope && scope.querySelectorAll ? scope : document;
		var roots = context.querySelectorAll( '.shd-igf' );
		var i;

		for ( i = 0; i < roots.length; i++ ) {
			init( roots[ i ] );
		}
	}

	// Elementor editor: widgets are re-rendered when settings change.
	function hookElementor() {
		if ( hookElementor.done || ! window.elementorFrontend || ! window.elementorFrontend.hooks ) {
			return !! hookElementor.done;
		}
		hookElementor.done = true;
		window.elementorFrontend.hooks.addAction( 'frontend/element_ready/shd-instagram-feed.default', function ( $scope ) {
			initAll( $scope && $scope[ 0 ] ? $scope[ 0 ] : null );
		} );
		return true;
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			initAll();
		} );
	} else {
		initAll();
	}

	if ( ! hookElementor() && window.jQuery ) {
		window.jQuery( window ).on( 'elementor/frontend/init', hookElementor );
	}
}() );
