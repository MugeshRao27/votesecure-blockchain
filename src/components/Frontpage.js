import React, { useEffect, useRef } from "react";
import { useNavigate } from "react-router-dom";

// ✅ Swiper Imports
import { Swiper, SwiperSlide } from "swiper/react";
import { Navigation, Pagination, A11y } from "swiper/modules";
import "swiper/css";
import "swiper/css/navigation";
import "swiper/css/pagination";
import "./Frontpage.css";

const Frontpage = () => {
  const navigate = useNavigate();

  // Refs for scroll animations
  const cardRefs = useRef([]);
  const howItWorksRefs = useRef([]);
  const arrowRefs = useRef([]);

  useEffect(() => {
    const observer = new IntersectionObserver(
      entries => {
        entries.forEach(entry => {
          if (entry.isIntersecting) {
            entry.target.classList.add("scroll-in");
          }
        });
      },
      { threshold: 0.1 }
    );

    cardRefs.current.forEach(card => card && observer.observe(card));
    howItWorksRefs.current.forEach(card => card && observer.observe(card));
    arrowRefs.current.forEach(arrow => arrow && observer.observe(arrow));

    return () => observer.disconnect();
  }, []);

  const addToRefs = el => {
    if (el && !cardRefs.current.includes(el)) cardRefs.current.push(el);
  };
  const addToHowItWorksRefs = el => {
    if (el && !howItWorksRefs.current.includes(el))
      howItWorksRefs.current.push(el);
  };
  const addToArrowRefs = el => {
    if (el && !arrowRefs.current.includes(el)) arrowRefs.current.push(el);
  };

  return (
    <div className="frontpage">
      {/* ================= NAVBAR ================= */}
      <nav className="navbar">
        <div className="nav-container">
          <h1 className="logo">VoteSecure</h1>
          <ul className="nav-links">
            <li><a href="#home">Home</a></li>
            <li><a href="#how-it-works">How It Works</a></li>
            <li><a href="#features">Features</a></li>
            <li><a href="#contact">Contact</a></li>
          </ul>
          <button className="menu-btn">&#9776;</button>
        </div>
      </nav>

      {/* ================= HERO SECTION ================= */}
      <section id="home" className="hero">
        <div className="hero-content">
          <h2>Secure. Smart. Digital Voting.</h2>
          <p>
            Experience the next generation of online elections with advanced encryption,
            transparency, and Face Verification + OTP authentication.
          </p>
          <button onClick={() => navigate("/auth")}>Get Started</button>
        </div>
      </section>

      {/* ================= HOW IT WORKS ================= */}
      <section id="how-it-works" className="how-it-works">
        <div className="how-it-works-header">
          <h2>How does it work?</h2>
          <p>
            Our Face Verification + OTP flow is designed for maximum security and ease of use.
            Here's how the process works:
          </p>
        </div>
        <div className="process-steps">
          <div className="process-card" ref={addToHowItWorksRefs}>
            <div className="step-number">01</div>
            <div className="step-content">
              <h3>Face Enrollment</h3>
              <p>
                Voters register and securely capture a face photo as part of their identity. 
                Admins create elections and manage eligible voters.
              </p>
            </div>
            <div className="step-icon">🙂</div>
          </div>
          <div className="arrow-container" ref={addToArrowRefs}>
            <div className="animated-arrow">→</div>
          </div>
          <div className="process-card" ref={addToHowItWorksRefs}>
            <div className="step-number">02</div>
            <div className="step-content">
               <h3>Secure Authentication</h3>
               <p>
                Voters authenticate with face verification. Optionally, an OTP is sent for a 
                second factor before access to vote. Ballots are encrypted and anonymous.
               </p>
            </div>
            <div className="step-icon">🔐</div>
          </div>
          <div className="arrow-container" ref={addToArrowRefs}>
            <div className="animated-arrow">→</div>
          </div>
          <div className="process-card" ref={addToHowItWorksRefs}>
            <div className="step-number">03</div>
            <div className="step-content">
              <h3>Results & Verification</h3>
              <p>
                Instant results with full transparency. Auditable counts ensure integrity, and
                voters receive confirmation without revealing their choice.
              </p>
            </div>
            <div className="step-icon">📊</div>
          </div>
        </div>
      </section>

      {/* ================= FEATURES SECTION ================= */}
      <section id="features" className="features">
        <div className="features-header">
          <h2>Transform your approach to voting</h2>
        </div>
        <div className="features-grid">
          <div className="feature-card" ref={addToRefs}>
            <div className="feature-icon">🔒</div>
            <h3>Security</h3>
            <p>No risk. No compromise. Every vote is protected, secret, and 100% verifiable.</p>
          </div>
          <div className="feature-card" ref={addToRefs}>
            <div className="feature-icon">👥</div>
            <h3>Assistance</h3>
            <p>Our team empowers you with dedicated guidance throughout your election process.</p>
          </div>
          <div className="feature-card" ref={addToRefs}>
            <div className="feature-icon">💡</div>
            <h3>Ease of use</h3>
            <p>Built to be intuitive from setup to results. No confusion. Just action.</p>
          </div>
          <div className="feature-card" ref={addToRefs}>
            <div className="feature-icon">🛡️</div>
            <h3>Privacy</h3>
            <p>Voter data remains safe and anonymous with full privacy compliance.</p>
          </div>
          <div className="feature-card" ref={addToRefs}>
            <div className="feature-icon">⚡</div>
            <h3>Speed</h3>
            <p>Instant results thanks to real-time counting. No waiting for manual tallying.</p>
          </div>
          <div className="feature-card" ref={addToRefs}>
            <div className="feature-icon">🌍</div>
            <h3>Accessibility</h3>
            <p>Vote from anywhere on any device. Democracy accessible to all.</p>
          </div>
        </div>
      </section>

      {/* ================= USE CASES - SWIPER CAROUSEL ================= */}
      <section className="usecase-section">
        <h2 className="usecase-title">Use Cases</h2>
        <Swiper
          className="usecase-swiper"
          modules={[Navigation, Pagination, A11y]}
          slidesPerView={1}
          navigation
          pagination={{ clickable: true }}
          loop={false}
          spaceBetween={24}
        >

          {/* College / University Elections */}
          <SwiperSlide>
            <div className="usecase-card" ref={addToRefs}>
              <div className="usecase-container">
                <div className="usecase-left">
                  <h3>College & University Elections</h3>
                  <p>
                    Run campus votes for student bodies, hostel committees, and talent shows with QR-based login.
                    Prevent impersonation and vote tampering using OTP authentication and anonymized ballot casting.
                  </p>
                </div>
                <div className="usecase-right">
                  <ol>
                    <li>
                      Student Council President:
                      <ul>
                        <li><input type="radio" name="president" /> Alex Thomas</li>
                        <li><input type="radio" name="president" /> Diya Sen</li>
                        <li><input type="radio" name="president" /> Rahil Singh</li>
                      </ul>
                    </li>
                    <li>
                      General Secretary:
                      <ul>
                        <li><input type="radio" name="secretary" /> Priya Nair</li>
                        <li><input type="radio" name="secretary" /> Aman Raj</li>
                      </ul>
                    </li>
                  </ol>
                </div>
              </div>
            </div>
          </SwiperSlide>

          {/* Local Body and Ward Elections */}
          <SwiperSlide>
            <div className="usecase-card" ref={addToRefs}>
              <div className="usecase-container">
                <div className="usecase-left">
                  <h3>Local Body & Panchayat Elections</h3>
                  <p>
                    Conduct municipality, ward, or panchayat elections securely. Voters authenticate using QR + OTP, cast their ballot confidentially, and access tamper-proof digital results.
                  </p>
                </div>
                <div className="usecase-right">
                  <ol>
                    <li>
                      Ward Councillor:
                      <ul>
                        <li><input type="radio" name="ward" /> Anita Sinha</li>
                        <li><input type="radio" name="ward" /> Bashir Khan</li>
                        <li><input type="radio" name="ward" /> Rahul Chatterjee</li>
                      </ul>
                    </li>
                    <li>
                      Women's Representative:
                      <ul>
                        <li><input type="radio" name="women_rep" /> Geeta Kumari</li>
                        <li><input type="radio" name="women_rep" /> Fatima Abbas</li>
                      </ul>
                    </li>
                  </ol>
                </div>
              </div>
            </div>
          </SwiperSlide>

          {/* Union or Association Elections */}
          <SwiperSlide>
            <div className="usecase-card" ref={addToRefs}>
              <div className="usecase-container">
                <div className="usecase-left">
                  <h3>Union & Association Elections</h3>
                  <p>
                    Empower employees, professional bodies, housing societies, and trade unions to vote for office bearers and policy decisions. Ensure fairness with identity validation and receipt-based result verification.
                  </p>
                </div>
                <div className="usecase-right">
                  <ol>
                    <li>
                      Union President:
                      <ul>
                        <li><input type="radio" name="union_president" /> Satish Mehra</li>
                        <li><input type="radio" name="union_president" /> Yusuf Ali</li>
                      </ul>
                    </li>
                    <li>
                      Treasurer:
                      <ul>
                        <li><input type="radio" name="treasurer" /> Monica Agarwal</li>
                        <li><input type="radio" name="treasurer" /> Ramanjit Singh</li>
                      </ul>
                    </li>
                  </ol>
                </div>
              </div>
            </div>
          </SwiperSlide>

          {/* Corporate & Remote Voting */}
          <SwiperSlide>
            <div className="usecase-card" ref={addToRefs}>
              <div className="usecase-container">
                <div className="usecase-left">
                  <h3>Corporate & Remote Elections</h3>
                  <p>
                    Enable teams, boards, and committees to elect leaders or vote on policies—securely, from any device or location, with instant validation and transparent records.
                  </p>
                </div>
                <div className="usecase-right">
                  <ol>
                    <li>
                      Employee Committee Head:
                      <ul>
                        <li><input type="radio" name="committee_head" /> Neha Gupta</li>
                        <li><input type="radio" name="committee_head" /> Arjun Rao</li>
                      </ul>
                    </li>
                    <li>
                      Club Leader:
                      <ul>
                        <li><input type="radio" name="club_leader" /> Aman Singh</li>
                        <li><input type="radio" name="club_leader" /> Rohan Das</li>
                      </ul>
                    </li>
                  </ol>
                </div>
              </div>
            </div>
          </SwiperSlide>
        </Swiper>
      </section>

      {/* ================= FOOTER SECTION ================= */}
      <footer className="footer">
        <div className="footer-container">
          <div className="footer-brand">
            <span className="footer-logo">VoteSecure</span>
            <span className="footer-copy">
              © {new Date().getFullYear()} VoteSecure. All rights reserved.
            </span>
          </div>
          <div className="footer-links">
            <a href="#features">Features</a>
            <a href="#how-it-works">How it Works</a>
            <a href="#contact">Contact</a>
          </div>
        </div>
      </footer>

    </div>
  );
};

export default Frontpage;
